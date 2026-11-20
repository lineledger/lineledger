<?php

namespace App\Services\References;

/**
 * Every database column that references `funds.id`. A fund still on a ledger line, a document line, a donation, a grant, a template, a budget or a payroll profile. A delete is
 * refused while one does (see {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php: every
 * live-schema column matching `fund_id`, and every FK targeting `funds`,
 * must appear in columns() or excludedColumns().
 */
final class FundReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'bill_lines', 'column' => 'fund_id'],
            ['table' => 'budgets', 'column' => 'fund_id'],
            ['table' => 'cheque_lines', 'column' => 'fund_id'],
            ['table' => 'credit_memo_lines', 'column' => 'fund_id'],
            ['table' => 'deposit_lines', 'column' => 'fund_id'],
            ['table' => 'donations', 'column' => 'fund_id'],
            ['table' => 'employee_payroll_profiles', 'column' => 'fund_id'],
            ['table' => 'estimate_lines', 'column' => 'fund_id'],
            ['table' => 'expense_lines', 'column' => 'fund_id'],
            ['table' => 'grants', 'column' => 'fund_id'],
            ['table' => 'invoice_lines', 'column' => 'fund_id'],
            ['table' => 'journal_entry_template_lines', 'column' => 'fund_id'],
            ['table' => 'journal_lines', 'column' => 'fund_id'],
            ['table' => 'purchase_order_lines', 'column' => 'fund_id'],
            ['table' => 'recurring_document_lines', 'column' => 'fund_id'],
            ['table' => 'recurring_journal_entry_lines', 'column' => 'fund_id'],
            ['table' => 'sales_order_lines', 'column' => 'fund_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'fund_id'],
            ['table' => 'stock_adjustment_lines', 'column' => 'fund_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'fund_id'],
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
