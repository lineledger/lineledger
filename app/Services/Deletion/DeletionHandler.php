<?php

namespace App\Services\Deletion;

use App\Enums\DeletableDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * What one transaction type adds to {@see TransactionDeleter}. The deleter does
 * everything every type shares (the journal entries, the lock date, filed tax
 * returns, reconciliations, stock, bank-feed matches, attachments, edit locks,
 * the tombstone and the audit row); a handler supplies the rest: who else may
 * point at the document, how to describe it, and what to clean up with it.
 *
 * Each method receives a document of the handler's own type
 * (DeletableDocuments routes it); handlers check that before relying on it.
 */
interface DeletionHandler
{
    public function type(): DeletableDocumentType;

    /**
     * The journal entries the document posted: usually its one entry, a grant
     * its award entry and each recognition's, a journal entry itself.
     * TransactionDeleter adds the reversal a void wrote for each.
     *
     * @return list<int>
     */
    public function journalEntryIds(Model $document): array;

    /**
     * Other records that still point at the document and must be dealt with
     * first (a payment applied to an invoice, a deposit holding a receipt).
     *
     * @return list<DeletionBlocker>
     */
    public function blockers(Model $document): array;

    /**
     * Things worth knowing before deleting (it was emailed, …). Never blocking.
     *
     * @return list<string>
     */
    public function warnings(Model $document): array;

    /**
     * The tax codes on the document's lines, for the filed-return guard.
     *
     * @return list<int>
     */
    public function taxCodeIds(Model $document): array;

    /**
     * The tombstone's summary columns.
     *
     * @return array{document_number: ?string, document_date: ?string, contact_name: ?string, total_cents: int, currency_code: ?string, bank_account_id?: ?int}
     */
    public function summary(Model $document): array;

    /**
     * The document's header, lines and links as they stood, readable without
     * the ids (names, codes, numbers, amounts).
     *
     * @return array<string, mixed>
     */
    public function snapshot(Model $document): array;

    /**
     * Remove the document, its lines and what it owns, and reset the records
     * that only pointed at it for information. Runs inside the deleter's
     * transaction, with the audit observer silenced, before the journal entries
     * are deleted. Note in $work what needs recomputing afterwards.
     */
    public function purge(Model $document, DeletionWork $work): void;

    /**
     * Recompute the document-level caches the delete moved (an invoice's paid
     * amount, a reconciliation release). Runs after the account balances and
     * the contacts' AR and AP are recomputed.
     */
    public function afterPurge(DeletionWork $work, int $companyId): void;
}
