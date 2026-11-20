<?php

namespace App\Services\Deletion;

use App\Enums\BankReconciliationStatus;
use App\Enums\StatementLineMatchStatus;
use App\Enums\TaxReturnStatus;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\InboxItem;
use App\Models\JournalEntry;
use App\Models\TaxReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The checks and clean-up every deletable transaction shares, beyond its own
 * handler: records elsewhere in the app that reference its journal entries or
 * lines, or the document itself through a polymorphic pointer.
 *
 * Nothing here relies on the database's SET NULL or CASCADE to clear a
 * reference: each one is either a reason to refuse (a filed tax return, a
 * fixed asset) or reset explicitly, so the record left behind is consistent.
 */
final class DeletionCleanup
{
    /**
     * A filed tax return froze these lines into its snapshot (tax_return_lines)
     * or chose to leave them out (excluded_journal_line_ids). Removing them
     * would make the ledger disagree with what was reported, whatever tax code
     * the line carries, so the return has to be voided first.
     *
     * @param  list<int>  $entryIds
     * @param  list<int>  $lineIds
     * @return list<DeletionBlocker>
     */
    public function filedTaxReturnBlockers(int $companyId, array $entryIds, array $lineIds, string $companySlug): array
    {
        if ($entryIds === []) {
            return [];
        }

        $returnIds = DB::table('tax_return_lines as l')
            ->join('tax_returns as r', 'r.id', '=', 'l.tax_return_id')
            ->where('r.company_id', $companyId)
            ->where('r.status', '!=', TaxReturnStatus::Void->value)
            ->whereNull('r.deleted_at')
            ->where(fn ($q) => $q->whereIn('l.journal_entry_id', $entryIds)->orWhereIn('l.journal_line_id', $lineIds === [] ? [0] : $lineIds))
            ->distinct()
            ->pluck('r.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (TaxReturn::withoutGlobalScopes()->where('company_id', $companyId)->where('status', TaxReturnStatus::Filed->value)->get(['id', 'excluded_journal_line_ids']) as $return) {
            if (array_intersect(array_map('intval', $return->excluded_journal_line_ids ?? []), $lineIds) !== []) {
                $returnIds[] = (int) $return->id;
            }
        }

        $blockers = [];

        foreach (TaxReturn::withoutGlobalScopes()->whereIn('id', array_unique($returnIds))->get(['id', 'tax_return_no']) as $return) {
            $blockers[] = new DeletionBlocker(
                __('Filed tax return :no includes this transaction. Void the return first if it really should not be there.', ['no' => (string) $return->tax_return_no]),
                route('tax-returns.show', ['company' => $companySlug, 'tax_return' => $return->id]),
            );
        }

        return $blockers;
    }

    /**
     * A fixed-asset record created from this document, or from one of its
     * journal entries ("Create asset record" on the journal page).
     *
     * @param  list<int>  $entryIds
     * @return list<DeletionBlocker>
     */
    public function assetBlockers(Model $document, array $entryIds, string $companySlug): array
    {
        $assets = Asset::withoutGlobalScopes()
            ->where('company_id', $document->getAttribute('company_id'))
            ->where(function ($q) use ($document, $entryIds): void {
                $q->where(fn ($q) => $q->where('source_type', $document::class)->where('source_id', $document->getKey()));

                if ($entryIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('source_type', JournalEntry::class)->whereIn('source_id', $entryIds));
                }
            })
            ->get(['id', 'asset_no', 'name']);

        return $assets->map(fn (Asset $asset): DeletionBlocker => new DeletionBlocker(
            __('Fixed asset :asset was created from this transaction. Delete the asset record first.', ['asset' => trim($asset->asset_no.' '.$asset->name)]),
            route('assets.show', ['company' => $companySlug, 'asset' => $asset->id]),
        ))->values()->all();
    }

    /**
     * Bank-feed lines matched to these lines, or that created these entries,
     * go back to the review queue, as in QuickBooks a deleted transaction's
     * bank-feed line returns to For Review.
     *
     * @param  list<int>  $entryIds
     * @param  list<int>  $lineIds
     */
    public function resetBankFeed(int $companyId, array $entryIds, array $lineIds): void
    {
        if ($entryIds === []) {
            return;
        }

        DB::table('bank_statement_lines')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereIn('created_journal_entry_id', $entryIds)->orWhereIn('matched_journal_line_id', $lineIds === [] ? [0] : $lineIds))
            ->update([
                'match_status' => StatementLineMatchStatus::Unmatched->value,
                'matched_journal_line_id' => null,
                'created_journal_entry_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Take the lines off reconciliations still in progress. A completed one
     * that ticked them refuses the delete earlier (BankReconciliationLockGuard).
     *
     * @param  list<int>  $lineIds
     */
    public function unmarkInProgressReconciliations(int $companyId, array $lineIds): void
    {
        if ($lineIds === []) {
            return;
        }

        $reconciliations = BankReconciliation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', BankReconciliationStatus::InProgress->value)
            ->get(['id', 'marked_line_ids']);

        foreach ($reconciliations as $reconciliation) {
            $marked = array_map('intval', $reconciliation->marked_line_ids ?? []);
            $kept = array_values(array_diff($marked, $lineIds));

            if (count($kept) !== count($marked)) {
                DB::table('bank_reconciliations')->where('id', $reconciliation->id)->update(['marked_line_ids' => json_encode($kept)]);
            }
        }
    }

    /**
     * Drop the lines from draft tax returns' exclusion lists.
     *
     * @param  list<int>  $lineIds
     */
    public function unexcludeFromDraftTaxReturns(int $companyId, array $lineIds): void
    {
        if ($lineIds === []) {
            return;
        }

        $returns = TaxReturn::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', TaxReturnStatus::Draft->value)
            ->get(['id', 'excluded_journal_line_ids']);

        foreach ($returns as $return) {
            $excluded = array_map('intval', $return->excluded_journal_line_ids ?? []);
            $kept = array_values(array_diff($excluded, $lineIds));

            if (count($kept) !== count($excluded)) {
                DB::table('tax_returns')->where('id', $return->id)->update(['excluded_journal_line_ids' => json_encode($kept)]);
            }
        }
    }

    /**
     * An MCP proposal that confirmed into one of these entries keeps its
     * history, minus the pointer.
     *
     * @param  list<int>  $entryIds
     */
    public function forgetConfirmedProposals(array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        DB::table('mcp_write_proposals')->whereIn('confirmed_journal_entry_id', $entryIds)->update(['confirmed_journal_entry_id' => null]);
    }

    /**
     * The document's attachments go with it: the rows now, the files once the
     * transaction commits (a rollback must not lose them). A file the inbox
     * captured is the inbox item's own, so it goes back to that item.
     */
    public function removeAttachments(Model $document, DeletionWork $work): void
    {
        $attachments = Attachment::withoutGlobalScopes()
            ->where('attachable_type', $document::class)
            ->where('attachable_id', $document->getKey())
            ->get();

        foreach ($attachments as $attachment) {
            $inboxItemId = InboxItem::withoutGlobalScopes()->where('attachment_id', $attachment->id)->value('id');

            if ($inboxItemId !== null) {
                DB::table('attachments')->where('id', $attachment->id)->update([
                    'attachable_type' => InboxItem::class,
                    'attachable_id' => $inboxItemId,
                ]);

                continue;
            }

            if (DB::table('bank_statement_imports')->where('attachment_id', $attachment->id)->exists()) {
                continue;
            }

            $work->blobs[] = ['disk' => (string) $attachment->disk, 'path' => (string) $attachment->path];
            DB::table('attachments')->where('id', $attachment->id)->delete();
        }
    }

    /**
     * Nobody can be editing a record that no longer exists.
     */
    public function removeEditLocks(Model $document): void
    {
        DB::table('edit_locks')
            ->where('lockable_type', $document::class)
            ->where('lockable_id', $document->getKey())
            ->delete();
    }
}
