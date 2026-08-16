<?php

namespace App\Services\Merge;

/**
 * Authoritative list of every database column that references `contacts.id`,
 * for the contact-merge feature.
 *
 * Semantics: these are REPOINT lists. When contact B (loser) is merged into
 * contact A (survivor), the merge action runs, for every entry in columns():
 *
 *     UPDATE {table} SET {column} = survivor_id WHERE {column} = loser_id
 *
 * Notes:
 *   - Several contact references hide under non-obvious names:
 *     `time_entries.customer_id` (bill-to customer) and the document-header
 *     `sales_rep_id` on invoices / estimates / sales_orders / credit_memos
 *     are all FKs to `contacts`.
 *   - `accounting_audit_logs` is immutable, hash-chained history. Its rows are
 *     never repointed (see excludedColumns()); the merge instead records its
 *     own `contact.merged` audit event.
 *   - Some columns sit under unique indexes that include the contact id (e.g.
 *     members.contact_id, employee_payroll_profiles.contact_id). The merge
 *     action is responsible for resolving any collisions before the blind
 *     UPDATE.
 *   - Attachments attach to contacts polymorphically — see morphTables().
 *
 * Guarded by tests/Unit/Merge/MergeReferenceRegistryTest.php: every live-schema
 * column matching `contact_id` / `*_contact_id`, and every FK targeting
 * `contacts`, must appear in columns() or excludedColumns().
 */
final class ContactReferenceRegistry
{
    /**
     * Every column to repoint from the loser to the survivor contact.
     *
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            // Self-FK (sub-customers): the loser's children re-parent to the
            // survivor — same as accounts.parent_id on account merges.
            ['table' => 'contacts', 'column' => 'parent_id'],

            // GL memo lines (AR/AP sub-ledger dimension).
            ['table' => 'journal_lines', 'column' => 'contact_id'],

            // Sales documents.
            ['table' => 'invoices', 'column' => 'contact_id'],
            ['table' => 'invoices', 'column' => 'sales_rep_id'],
            ['table' => 'estimates', 'column' => 'contact_id'],
            ['table' => 'estimates', 'column' => 'sales_rep_id'],
            ['table' => 'sales_orders', 'column' => 'contact_id'],
            ['table' => 'sales_orders', 'column' => 'sales_rep_id'],
            ['table' => 'credit_memos', 'column' => 'contact_id'],
            ['table' => 'credit_memos', 'column' => 'sales_rep_id'],
            ['table' => 'customer_receipts', 'column' => 'contact_id'],
            ['table' => 'sales_receipts', 'column' => 'contact_id'],
            ['table' => 'deposit_lines', 'column' => 'contact_id'],
            ['table' => 'donations', 'column' => 'contact_id'],
            ['table' => 'donation_receipts', 'column' => 'contact_id'],
            ['table' => 'grants', 'column' => 'funder_contact_id'],
            ['table' => 'members', 'column' => 'contact_id'],

            // Purchase documents.
            ['table' => 'bills', 'column' => 'contact_id'],
            ['table' => 'vendor_credits', 'column' => 'contact_id'],
            ['table' => 'bill_payments', 'column' => 'contact_id'],
            ['table' => 'cheques', 'column' => 'payee_contact_id'],
            ['table' => 'purchase_orders', 'column' => 'contact_id'],
            ['table' => 'expenses', 'column' => 'payee_contact_id'],

            // Payroll.
            ['table' => 'employee_payroll_profiles', 'column' => 'contact_id'],
            ['table' => 'pay_run_lines', 'column' => 'contact_id'],
            ['table' => 'payroll_cheques', 'column' => 'payee_contact_id'],
            // Finalized slip snapshots follow the surviving contact so the
            // employee portal keeps serving them after a merge.
            ['table' => 'payroll_slip_filing_lines', 'column' => 'contact_id'],
            // The employee who worked the entry, and the bill-to customer.
            ['table' => 'time_entries', 'column' => 'contact_id'],
            ['table' => 'time_entries', 'column' => 'customer_id'],
            // Time-off requests follow the surviving employee.
            ['table' => 'time_off_requests', 'column' => 'contact_id'],

            // Recurring templates.
            ['table' => 'recurring_documents', 'column' => 'contact_id'],
            ['table' => 'recurring_journal_entry_lines', 'column' => 'contact_id'],

            // Customer portal magic-link tokens follow the surviving contact.
            ['table' => 'portal_login_links', 'column' => 'contact_id'],

            // Inbox (AI document capture): the suggested payee/customer for an
            // unprocessed inbox item follows the surviving contact, mirroring
            // bank_statement_lines.suggested_account_id on account merges.
            ['table' => 'inbox_items', 'column' => 'suggested_contact_id'],
        ];
    }

    /**
     * Columns whose name matches the `contact_id` pattern (or whose FK targets
     * `contacts`) but that must NOT be repointed by a merge.
     *
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function excludedColumns(): array
    {
        return [
            [
                'table' => 'accounting_audit_logs',
                'column' => 'auditable_id',
                'reason' => 'immutable hash-chained audit history; the auditable morph may point at the loser contact but history is never rewritten — the merge records its own contact.merged event',
            ],
        ];
    }

    /**
     * Polymorphic references to contacts. This app stores FQCN morph types
     * (no morphMap), so the repoint is:
     *
     *     UPDATE {table} SET {idColumn} = survivor_id
     *     WHERE {typeColumn} = 'App\\Models\\Contact' AND {idColumn} = loser_id
     *
     * @return list<array{table: string, typeColumn: string, idColumn: string}>
     */
    public static function morphTables(): array
    {
        return [
            ['table' => 'attachments', 'typeColumn' => 'attachable_type', 'idColumn' => 'attachable_id'],
        ];
    }
}
