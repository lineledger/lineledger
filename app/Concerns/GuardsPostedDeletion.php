<?php

namespace App\Concerns;

use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Services\Deletion\TransactionDeleter;

/**
 * Once a financial document is posted to the general ledger it owns a journal
 * entry. Deleting the document anywhere but through
 * {@see TransactionDeleter} would orphan that entry (or the void's reversal)
 * and silently distort the ledger, so such a delete is refused here at the
 * model layer — covering every path (API, Livewire, queued jobs, console)
 * rather than relying on each call site to remember the check.
 *
 * TransactionDeleter is the one way through: it takes the journal entries,
 * stock and everything else the document touched out with it, and records
 * the deletion in the audit trail. Drafts (no journal entry yet) still delete
 * normally.
 *
 * The guard fires for soft deletes and force deletes alike. Company teardown is
 * a database-level delete, not an Eloquent one, so it is unaffected.
 *
 * Consuming models must expose a nullable `journal_entry_id`, or name their
 * entry column with a public postedEntryColumn() (a grant's award entry).
 */
trait GuardsPostedDeletion
{
    public static function bootGuardsPostedDeletion(): void
    {
        static::deleting(function ($model): void {
            $column = method_exists($model, 'postedEntryColumn') ? $model->postedEntryColumn() : 'journal_entry_id';

            if ($model->getAttribute($column) !== null && ! TransactionDeleter::running()) {
                throw PostedDocumentDeletionException::for($model);
            }
        });
    }
}
