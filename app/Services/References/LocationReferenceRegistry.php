<?php

namespace App\Services\References;

/**
 * Every database column that references `locations.id`. A location still on a ledger line, a document line, a template, a budget or a payroll profile. A delete is
 * refused while one does (see {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php: every
 * live-schema column matching `location_id`, and every FK targeting `locations`,
 * must appear in columns() or excludedColumns().
 */
final class LocationReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'bill_lines', 'column' => 'location_id'],
            ['table' => 'budgets', 'column' => 'location_id'],
            ['table' => 'cheque_lines', 'column' => 'location_id'],
            ['table' => 'credit_memo_lines', 'column' => 'location_id'],
            ['table' => 'deposit_lines', 'column' => 'location_id'],
            ['table' => 'employee_payroll_profiles', 'column' => 'location_id'],
            ['table' => 'estimate_lines', 'column' => 'location_id'],
            ['table' => 'expense_lines', 'column' => 'location_id'],
            ['table' => 'invoice_lines', 'column' => 'location_id'],
            ['table' => 'invoice_template_lines', 'column' => 'location_id'],
            ['table' => 'journal_entry_template_lines', 'column' => 'location_id'],
            ['table' => 'journal_lines', 'column' => 'location_id'],
            ['table' => 'pay_run_line_earnings', 'column' => 'location_id'],
            ['table' => 'purchase_order_lines', 'column' => 'location_id'],
            ['table' => 'recurring_document_lines', 'column' => 'location_id'],
            ['table' => 'recurring_journal_entry_lines', 'column' => 'location_id'],
            ['table' => 'sales_order_lines', 'column' => 'location_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'location_id'],
            ['table' => 'stock_adjustment_lines', 'column' => 'location_id'],
            ['table' => 'time_entries', 'column' => 'location_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'location_id'],
        ];
    }

    /**
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function excludedColumns(): array
    {
        return [];
    }
}
