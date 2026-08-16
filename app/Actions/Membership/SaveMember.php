<?php

namespace App\Actions\Membership;

use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurringDocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Member;
use App\Models\RecurringDocument;
use App\Services\Posting\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a membership record. On create it assigns the member number
 * and flags the linked contact as a member (and a customer, so dues can be billed
 * as invoices). When auto-renew is on it keeps a recurring-invoice schedule in sync
 * so the standard recurring engine generates renewal dues drafts; turning it off
 * pauses that schedule. Shared by the Livewire pages and the API.
 *
 * Expected $data shape:
 *   contact_id:          int
 *   membership_level_id: ?int
 *   joined_on:           ?string
 *   started_on:          ?string
 *   expires_on:          ?string
 *   dues_cents:          ?int
 *   auto_renew:          ?bool
 *   notes:               ?string
 *   is_active:           ?bool
 */
final class SaveMember
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected SaveRecurringDocument $saveRecurringDocument,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Member $member = null): Member
    {
        $company = app('current_company');

        return DB::transaction(function () use ($data, $member, $company): Member {
            $attributes = [
                'contact_id' => $data['contact_id'],
                'membership_level_id' => $data['membership_level_id'] ?? null,
                'joined_on' => $data['joined_on'] ?? null,
                'started_on' => $data['started_on'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'dues_cents' => $data['dues_cents'] ?? null,
                'auto_renew' => (bool) ($data['auto_renew'] ?? false),
                'notes' => $data['notes'] ?? null,
            ];

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            if ($member && $member->exists) {
                $member->update($attributes);
            } else {
                $member = Member::create($attributes + [
                    'member_no' => $this->numbers->next($company, Member::class, 'member_no', 'MEM'),
                    'is_active' => $data['is_active'] ?? true,
                ]);
            }

            // A member is invoiced for dues, so they must be a customer too.
            Contact::query()
                ->whereKey($member->contact_id)
                ->update(['is_member' => true, 'is_customer' => true]);

            $this->syncAutoRenewSchedule($member, $company);

            return $member;
        });
    }

    /**
     * Keep the member's renewal schedule in step with the auto-renew flag and the
     * current level/dues. When auto-renew is on (and a billable level exists) the
     * schedule is created or updated and linked via members.recurring_document_id;
     * when it is off (or no longer billable) any existing schedule is paused.
     */
    protected function syncAutoRenewSchedule(Member $member, Company $company): void
    {
        $member->loadMissing('level');
        $level = $member->level;

        $existing = $member->recurring_document_id !== null
            ? RecurringDocument::query()->find($member->recurring_document_id)
            : null;

        $billable = $member->auto_renew
            && $member->is_active
            && $level !== null
            && $level->revenue_account_id !== null
            && $member->effectiveDuesCents() > 0;

        if (! $billable) {
            if ($existing !== null && $existing->is_active) {
                $existing->forceFill([
                    'is_active' => false,
                    'next_run_date' => null,
                    'paused_reason' => 'Membership auto-renew turned off.',
                ])->save();
            }

            return;
        }

        // The first renewal falls due at the current term end; lifetime/no-term
        // members anchor to the term start (or today).
        $startDate = $member->expires_on?->toDateString()
            ?? $member->started_on?->toDateString()
            ?? $company->currentDateTime()->toDateString();

        $document = $this->saveRecurringDocument->handle([
            'document_type' => RecurringDocumentType::Invoice->value,
            'contact_id' => $member->contact_id,
            'terms_id' => $level->default_terms_id,
            'memo' => "Membership dues — {$level->name} ({$member->member_no})",
            'name' => "Membership renewal — {$level->name}",
            'frequency' => $level->billing_frequency->value,
            'start_date' => $startDate,
            'end_type' => RecurrenceEndType::Never->value,
            'lines' => [[
                'account_id' => $level->revenue_account_id,
                'description' => "Membership dues: {$level->name}",
                'quantity' => 1,
                'unit_price_cents' => $member->effectiveDuesCents(),
                'tax_code_id' => $level->default_tax_code_id,
            ]],
        ], $existing);

        // Reactivate a previously paused schedule and restore its run date.
        if (! $document->is_active || $document->next_run_date === null) {
            $document->forceFill([
                'is_active' => true,
                'paused_reason' => null,
                'next_run_date' => $document->next_run_date?->toDateString() ?? $startDate,
            ])->save();
        }

        if ($member->recurring_document_id !== $document->id) {
            $member->forceFill(['recurring_document_id' => $document->id])->save();
        }
    }
}
