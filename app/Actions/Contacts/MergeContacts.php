<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Models\Contact;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Merge\ContactReferenceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges a duplicate contact (the loser) into a surviving contact: every
 * reference in {@see ContactReferenceRegistry::columns()} plus the attachments
 * morph is repointed to the survivor, role flags are unioned onto the survivor
 * (tax identifiers copied only where the survivor's are empty), the survivor's
 * cached AR/AP balances are recomputed, and the loser is deactivated and
 * soft-deleted. Irreversible by design.
 *
 * Unlike the Save* actions (one entity + a data array), this is a two-entity
 * operation — handle(loser, survivor) — following the ReverseJournalEntry
 * precedent of an Action whose subject is a relationship between two records.
 *
 * The company lock_date deliberately does NOT block a merge: no GL amounts or
 * dates change — journal lines keep their debits/credits and only their
 * contact dimension moves — so locked-period totals are unaffected.
 */
final class MergeContacts
{
    public function __construct(protected AccountingAuditRecorder $recorder) {}

    public function handle(Contact $loser, Contact $survivor): Contact
    {
        if ($loser->id === $survivor->id) {
            throw ValidationException::withMessages([
                'merge' => __('A contact cannot be merged into itself.'),
            ]);
        }

        if ($loser->company_id !== $survivor->company_id
            || (app()->bound('current_company') && $loser->company_id !== app('current_company')->id)) {
            throw ValidationException::withMessages([
                'merge' => __('Both contacts must belong to the current company.'),
            ]);
        }

        if (! $survivor->is_active || $survivor->trashed()) {
            throw ValidationException::withMessages([
                'merge' => __('The surviving contact must be active.'),
            ]);
        }

        if ($loser->currency_code !== $survivor->currency_code) {
            throw ValidationException::withMessages([
                'merge' => __('Contacts must have the same currency to be merged.'),
            ]);
        }

        if ($this->survivorDescendsFromLoser($loser, $survivor)) {
            throw ValidationException::withMessages([
                'merge' => __('A customer cannot be merged into one of its own sub-customers. Choose a different surviving customer.'),
            ]);
        }

        // pay_run_lines is unique on (pay_run_id, contact_id) — two lines in
        // the same run cannot collapse onto one contact, and a run that paid
        // both people is real history that should not be rewritten.
        // (Checked before the payroll-profile guard so each guard is
        // independently reachable: a shared pay run implies both profiles.)
        $sharedPayRuns = DB::table('pay_run_lines')
            ->where('contact_id', $loser->id)
            ->whereIn('pay_run_id', function ($query) use ($survivor) {
                $query->select('pay_run_id')
                    ->from('pay_run_lines')
                    ->where('contact_id', $survivor->id);
            })
            ->exists();

        if ($sharedPayRuns) {
            throw ValidationException::withMessages([
                'merge' => __('These contacts both appear in the same pay run and cannot be merged.'),
            ]);
        }

        // employee_payroll_profiles is unique on (company_id, contact_id);
        // two profiles also mean two distinct payroll/T4 identities.
        $bothHaveProfiles = DB::table('employee_payroll_profiles')->where('contact_id', $loser->id)->exists()
            && DB::table('employee_payroll_profiles')->where('contact_id', $survivor->id)->exists();

        if ($bothHaveProfiles) {
            throw ValidationException::withMessages([
                'merge' => __('Both contacts have employee payroll profiles and cannot be merged.'),
            ]);
        }

        // members is unique on (company_id, contact_id) — two membership
        // records cannot collapse onto one contact.
        $bothAreMembers = DB::table('members')->where('contact_id', $loser->id)->exists()
            && DB::table('members')->where('contact_id', $survivor->id)->exists();

        if ($bothAreMembers) {
            throw ValidationException::withMessages([
                'merge' => __('Both contacts have membership records and cannot be merged.'),
            ]);
        }

        return DB::transaction(function () use ($loser, $survivor): Contact {
            $moved = [];

            AuditMute::silence(function () use ($loser, $survivor, &$moved): void {
                foreach (ContactReferenceRegistry::columns() as $ref) {
                    $count = DB::table($ref['table'])
                        ->where($ref['column'], $loser->id)
                        ->update([$ref['column'] => $survivor->id]);

                    if ($count > 0) {
                        $moved["{$ref['table']}.{$ref['column']}"] = $count;
                    }
                }

                foreach (ContactReferenceRegistry::morphTables() as $morph) {
                    $count = DB::table($morph['table'])
                        ->where($morph['typeColumn'], (new Contact)->getMorphClass())
                        ->where($morph['idColumn'], $loser->id)
                        ->update([$morph['idColumn'] => $survivor->id]);

                    if ($count > 0) {
                        $moved["{$morph['table']}.{$morph['idColumn']}"] = $count;
                    }
                }

                // Union role flags; copy tax identifiers only where the
                // survivor's own are empty/false.
                $updates = [];
                foreach (['is_customer', 'is_vendor', 'is_employee', 'is_donor', 'is_member'] as $flag) {
                    if ($loser->{$flag} && ! $survivor->{$flag}) {
                        $updates[$flag] = true;
                    }
                }
                if (($survivor->tax_number === null || $survivor->tax_number === '') && $loser->tax_number) {
                    $updates['tax_number'] = $loser->tax_number;
                }
                if (! $survivor->track_1099 && $loser->track_1099) {
                    $updates['track_1099'] = true;
                }
                if (! $survivor->track_t4a && $loser->track_t4a) {
                    $updates['track_t4a'] = true;
                }
                if ($updates !== []) {
                    $survivor->forceFill($updates)->saveQuietly();
                }

                $survivor->recomputeArBalance();
                $survivor->recomputeApBalance();

                $loser->forceFill([
                    'is_active' => false,
                    'ar_balance_cents' => 0,
                    'ap_balance_cents' => 0,
                ])->saveQuietly();
                $loser->delete();
            });

            $this->recorder->record($loser->company_id, AuditAction::ContactMerged, $loser, [
                'merged_into' => ['id' => $survivor->id, 'display_name' => $survivor->display_name],
                'moved' => $moved,
            ]);

            $this->recorder->record($survivor->company_id, AuditAction::ContactMerged, $survivor, [
                'absorbed' => ['id' => $loser->id, 'display_name' => $loser->display_name],
                'moved' => $moved,
            ]);

            return $survivor->refresh();
        });
    }

    /**
     * Walk the survivor's parent chain; if the loser appears, the merge would
     * orphan the survivor (its ancestor is about to be deleted) or create a
     * parent cycle via the registry's contacts.parent_id repoint.
     */
    private function survivorDescendsFromLoser(Contact $loser, Contact $survivor): bool
    {
        $seen = [];
        $parentId = $survivor->parent_id;

        while ($parentId !== null && ! isset($seen[$parentId])) {
            if ((int) $parentId === (int) $loser->id) {
                return true;
            }

            $seen[$parentId] = true;
            $parentId = Contact::withoutGlobalScopes()
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }
}
