<?php

namespace App\Concerns;

use App\Exceptions\Posting\PostedDocumentDeletionException;

/**
 * Once a financial document is posted to the general ledger it owns an
 * immutable journal entry. Deleting the document would orphan that entry and
 * silently distort the ledger, so deletion is refused here at the model layer —
 * covering every path (API, Livewire, queued jobs, console) rather than relying
 * on each call site to remember the check. Posted documents are unwound the
 * accounting way, by voiding: that keeps both the document and a reversing entry
 * as a permanent record. Drafts (no journal entry yet) delete normally.
 *
 * The guard fires for soft deletes and force deletes alike. Company teardown is
 * a database-level FK cascade, not an Eloquent delete, so it is unaffected.
 *
 * Consuming models must expose a nullable `journal_entry_id`.
 */
trait GuardsPostedDeletion
{
    public static function bootGuardsPostedDeletion(): void
    {
        static::deleting(function ($model): void {
            if ($model->journal_entry_id !== null) {
                throw PostedDocumentDeletionException::for($model);
            }
        });
    }
}
