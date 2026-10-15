<?php

namespace App\Actions\Accounting;

use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes a manual journal entry that was never posted. Shared by
 * the journal show page and the API.
 *
 * A draft has never touched the general ledger, so there is nothing to reverse:
 * the entry and its lines are simply deleted. Posted entries are refused — they
 * are unwound with {@see JournalPoster::void()}, which keeps the original and a
 * reversing entry as a permanent record. Source-linked entries are refused too,
 * because the originating document (Deposit, Invoice, …) owns them.
 *
 * Lines are deleted one model at a time (not a bulk query) so the audit observer
 * records each removal alongside the entry's own journal_entry.deleted row.
 */
final class DeleteDraftJournalEntry
{
    public function handle(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            // Re-read under a row lock so an entry posted after this request
            // loaded it is still refused.
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->isPosted()) {
                throw PostedDocumentDeletionException::for($entry);
            }

            if ($entry->source_type !== null) {
                throw LinkedJournalEntryException::for($entry);
            }

            $entry->lines->each->delete();
            $entry->delete();
        });
    }
}
