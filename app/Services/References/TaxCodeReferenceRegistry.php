<?php

namespace App\Services\References;

/**
 * Every database column that references `tax_codes.id`. Deleting a tax code
 * that any of these still names would, through the FKs' SET NULL, silently
 * strip it from posted document lines and the ledger's tax tags, so the
 * delete is refused while one does (see {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php: every
 * live-schema column matching `tax_code_id` / `*_tax_code_id`, and every FK
 * targeting `tax_codes`, must appear in columns() or excludedColumns().
 */
final class TaxCodeReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            // The general ledger's tax tag (no FK, so nothing else stops a delete).
            ['table' => 'journal_lines', 'column' => 'tax_code_id'],

            // Sales documents.
            ['table' => 'invoice_lines', 'column' => 'tax_code_id'],
            ['table' => 'invoice_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'tax_code_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'credit_memo_lines', 'column' => 'tax_code_id'],
            ['table' => 'credit_memo_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'estimate_lines', 'column' => 'tax_code_id'],
            ['table' => 'estimate_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'sales_order_lines', 'column' => 'tax_code_id'],
            ['table' => 'sales_order_lines', 'column' => 'secondary_tax_code_id'],

            // Purchase documents.
            ['table' => 'bill_lines', 'column' => 'tax_code_id'],
            ['table' => 'bill_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'tax_code_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'expense_lines', 'column' => 'tax_code_id'],
            ['table' => 'expense_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'cheque_lines', 'column' => 'tax_code_id'],
            ['table' => 'cheque_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'purchase_order_lines', 'column' => 'tax_code_id'],
            ['table' => 'purchase_order_lines', 'column' => 'secondary_tax_code_id'],

            // Templates and recurring schedules.
            ['table' => 'invoice_template_lines', 'column' => 'tax_code_id'],
            ['table' => 'invoice_template_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'recurring_document_lines', 'column' => 'tax_code_id'],
            ['table' => 'recurring_document_lines', 'column' => 'secondary_tax_code_id'],
            ['table' => 'journal_entry_template_lines', 'column' => 'tax_code_id'],

            // List defaults.
            ['table' => 'accounts', 'column' => 'default_tax_code_id'],
            ['table' => 'contacts', 'column' => 'default_tax_code_id'],
            ['table' => 'items', 'column' => 'default_tax_code_id'],
            ['table' => 'items', 'column' => 'default_secondary_tax_code_id'],
            ['table' => 'membership_levels', 'column' => 'default_tax_code_id'],

            // Bank-feed suggestions not yet confirmed.
            ['table' => 'bank_statement_lines', 'column' => 'suggested_tax_code_id'],
            ['table' => 'bank_statement_lines', 'column' => 'suggested_secondary_tax_code_id'],
        ];
    }

    /**
     * Columns matching the pattern that are not references to tax_codes.id.
     *
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function excludedColumns(): array
    {
        return [];
    }
}
