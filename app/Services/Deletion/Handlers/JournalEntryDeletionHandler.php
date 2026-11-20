<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\JournalEntry;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a manual journal entry, a bank-feed entry or a QuickBooks-imported
 * one (JournalEntry::isDeletableInJournal()). The entry is the document, so
 * TransactionDeleter removes it with its lines (and, for a voided one, the
 * void's reversal). An entry a document, a reconciliation, a revaluation or
 * the opening balances own is managed there; the reversal a void wrote goes
 * with the voided entry; an accrual reversal or a fixed asset's depreciation
 * pointing at it blocks.
 */
final class JournalEntryDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::JournalEntry;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): ?string
    {
        return null;
    }

    /**
     * A journal entry is its own document.
     */
    public function journalEntryIds(Model $document): array
    {
        return [(int) $this->entry($document)->id];
    }

    public function blockers(Model $document): array
    {
        $entry = $this->entry($document);
        $blockers = [];

        if (! $entry->isDeletableInJournal()) {
            $message = LinkedJournalEntryException::for($entry)->getMessage();

            $blockers[] = $entry->isVoidReversal()
                ? $this->blocker($document, __('This entry reverses voided entry :no. Delete that entry instead; its reversal goes with it.', ['no' => (string) $entry->reverses?->entry_no]), 'journal.show', ['entry' => $entry->reverses_entry_id])
                : new DeletionBlocker($message);
        }

        $ownReversal = $entry->isVoided() ? $entry->reversed_by_entry_id : null;

        foreach (JournalEntry::withoutGlobalScopes()->where('company_id', $entry->company_id)->where('reverses_entry_id', $entry->id)->when($ownReversal !== null, fn ($q) => $q->whereKeyNot($ownReversal))->get(['id', 'entry_no']) as $reversal) {
            $blockers[] = $this->blocker($document, __('Entry :no reverses this entry. Delete it first.', ['no' => (string) $reversal->entry_no]), 'journal.show', ['entry' => $reversal->id]);
        }

        if (DB::table('asset_depreciation_entries')->where('journal_entry_id', $entry->id)->exists()) {
            $blockers[] = new DeletionBlocker(__('This entry records depreciation for a fixed asset, and the asset\'s schedule manages it.'));
        }

        if (DB::table('currency_revaluations')->where('journal_entry_id', $entry->id)->orWhere('reversal_entry_id', $entry->id)->exists()) {
            $blockers[] = new DeletionBlocker(__('This entry belongs to a currency revaluation, which manages it.'));
        }

        return $blockers;
    }

    public function warnings(Model $document): array
    {
        return $this->entry($document)->source_external_id !== null
            ? [__('It came from a QuickBooks import: running that import again would bring it back.')]
            : [];
    }

    public function summary(Model $document): array
    {
        $entry = $this->entry($document);
        $entry->loadMissing('lines.contact');

        return [
            'document_number' => $this->stringOrNull($entry->entry_no),
            'document_date' => SnapshotValue::date($entry->entry_date),
            'contact_name' => $entry->lines->first(fn ($line) => $line->contact_id !== null)?->contact?->display_name,
            'total_cents' => (int) $entry->lines->sum('debit_cents'),
            'currency_code' => null,
        ];
    }

    /**
     * The entry and its lines go with the rest of the transaction's entries
     * (TransactionDeleter), so there is nothing more to remove here.
     */
    public function purge(Model $document, DeletionWork $work): void
    {
        $this->entry($document);
    }

    private function entry(Model $document): JournalEntry
    {
        $entry = $this->own($document);

        return $entry instanceof JournalEntry ? $entry : throw new \InvalidArgumentException('Expected a journal entry.');
    }
}
