<?php

namespace App\Services\Deletion;

/**
 * Every reference into a deletable transaction's table, and into the journal
 * entries and lines it owns, with what a delete does about it:
 *
 *  - BLOCKS: another record depends on it. The delete is refused, naming it,
 *    until that record is dealt with (a payment applied to the invoice, a
 *    deposit holding the receipt, a filed return's snapshot).
 *  - OWNED: part of the transaction; removed with it (its lines, a payment's
 *    applications, its stock movements, the void's reversal).
 *  - RESET: a pointer kept for information; cleared or restored, never a
 *    reason to refuse (an estimate's conversion, billed time, a bank-feed
 *    match).
 *  - OWNER: a record's pointer at the journal entry it owns. TransactionDeleter
 *    only deletes the entries a deleted document owns, so any other owner's
 *    entry is out of reach.
 *  - HISTORY: immutable history, left as it is (the audit trail).
 *
 * tests/Feature/Deletion/DocumentReferenceRegistryTest.php builds the list of
 * references from the restore maps (App\Services\Restore\RowTransformer), which
 * are themselves tested against the schema, and fails on one missing here: a
 * new table that points at invoices fails CI until someone decides what a
 * delete does about it.
 */
final class DocumentReferenceRegistry
{
    public const BLOCKS = 'blocks';

    public const OWNED = 'owned';

    public const RESET = 'reset';

    public const OWNER = 'owner';

    public const HISTORY = 'history';

    /**
     * Target table => ["table.column" => disposition].
     *
     * @return array<string, array<string, string>>
     */
    public static function references(): array
    {
        return [
            'invoices' => [
                'receipt_applications.invoice_id' => self::BLOCKS,
                'invoice_lines.invoice_id' => self::OWNED,
                'invoice_payment_requests.invoice_id' => self::OWNED,
                'invoice_reminder_logs.invoice_id' => self::OWNED,
                'estimates.converted_invoice_id' => self::RESET,
                'time_entries.invoice_id' => self::RESET,
                'cheques.from_invoice_id' => self::RESET,
                'customer_receipts.from_invoice_id' => self::RESET,
            ],
            'sales_receipts' => [
                'deposit_lines.sales_receipt_id' => self::BLOCKS,
                'sales_receipt_lines.sales_receipt_id' => self::OWNED,
            ],
            'customer_receipts' => [
                'receipt_applications.customer_receipt_id' => self::OWNED,
                'deposit_lines.customer_receipt_id' => self::BLOCKS,
                'customer_receipts.refunded_receipt_id' => self::BLOCKS,
                'cheques.refunded_receipt_id' => self::BLOCKS,
                'donation_receipts.customer_receipt_id' => self::BLOCKS,
            ],
            'credit_memos' => [
                'credit_memo_lines.credit_memo_id' => self::OWNED,
                'cheques.credit_memo_id' => self::BLOCKS,
                'customer_receipts.credit_memo_id' => self::BLOCKS,
            ],
            'bills' => [
                'bill_lines.bill_id' => self::OWNED,
                'bill_payment_applications.bill_id' => self::BLOCKS,
                'bank_statement_lines.suggested_bill_id' => self::RESET,
                'bank_statement_lines.suggested_bill_allocations' => self::RESET,
                'inbox_items.promoted_document_id' => self::RESET,
            ],
            'bill_payments' => [
                'bill_payment_applications.bill_payment_id' => self::OWNED,
            ],
            'vendor_credits' => [
                'vendor_credit_lines.vendor_credit_id' => self::OWNED,
            ],
            'expenses' => [
                'expense_lines.expense_id' => self::OWNED,
                'inbox_items.promoted_document_id' => self::RESET,
            ],
            'cheques' => [
                'cheque_lines.cheque_id' => self::OWNED,
            ],
            'deposits' => [
                'deposit_lines.deposit_id' => self::OWNED,
            ],
            'transfers' => [],
            'estimates' => [
                'estimate_lines.estimate_id' => self::OWNED,
            ],
            'sales_orders' => [
                'sales_order_lines.sales_order_id' => self::OWNED,
                'invoices.sales_order_id' => self::BLOCKS, // and invoice lines on its lines
                'estimates.converted_sales_order_id' => self::RESET,
            ],
            'purchase_orders' => [
                'purchase_order_lines.purchase_order_id' => self::OWNED,
                'bills.purchase_order_id' => self::BLOCKS, // and bill lines on its lines
            ],
            'stock_adjustments' => [
                'stock_adjustment_lines.stock_adjustment_id' => self::OWNED,
            ],
            'tax_return_payments' => [],
            'donations' => [
                'donation_receipts.donation_id' => self::BLOCKS, // an issued or voided receipt; a draft one goes with it
            ],
            'grants' => [
                'grant_recognitions.grant_id' => self::OWNED,
            ],
            'pay_runs' => [
                'pay_run_lines.pay_run_id' => self::OWNED,
                'payroll_cheques.pay_run_id' => self::OWNED, // draft and voided ones; a posted cheque blocks
                'time_entries.pay_run_id' => self::RESET,
            ],
            'payroll_cheques' => [],
            'payroll_remittances' => [],
            'journal_entries' => [
                'journal_lines.journal_entry_id' => self::OWNED,
                'journal_entries.reverses_entry_id' => self::OWNED,
                'journal_entries.reversed_by_entry_id' => self::OWNED,
                'stock_movements.journal_entry_id' => self::OWNED,
                'bank_statement_lines.created_journal_entry_id' => self::RESET,
                'mcp_write_proposals.confirmed_journal_entry_id' => self::RESET,
                'tax_return_lines.journal_entry_id' => self::BLOCKS,
                'accounting_audit_logs.journal_entry_id' => self::HISTORY,
                'invoices.journal_entry_id' => self::OWNER,
                'sales_receipts.journal_entry_id' => self::OWNER,
                'customer_receipts.journal_entry_id' => self::OWNER,
                'bills.journal_entry_id' => self::OWNER,
                'bill_payments.journal_entry_id' => self::OWNER,
                'cheques.journal_entry_id' => self::OWNER,
                'credit_memos.journal_entry_id' => self::OWNER,
                'vendor_credits.journal_entry_id' => self::OWNER,
                'deposits.journal_entry_id' => self::OWNER,
                'expenses.journal_entry_id' => self::OWNER,
                'transfers.journal_entry_id' => self::OWNER,
                'stock_adjustments.journal_entry_id' => self::OWNER,
                'tax_return_payments.journal_entry_id' => self::OWNER,
                'tax_returns.adjustment_journal_entry_id' => self::OWNER,
                'donations.journal_entry_id' => self::OWNER,
                'donation_receipts.journal_entry_id' => self::OWNER,
                'grants.award_journal_entry_id' => self::OWNER,
                'grant_recognitions.journal_entry_id' => self::OWNER,
                'pay_runs.journal_entry_id' => self::OWNER,
                'payroll_cheques.journal_entry_id' => self::OWNER,
                'payroll_remittances.journal_entry_id' => self::OWNER,
                'currency_revaluations.journal_entry_id' => self::OWNER,
                'currency_revaluations.reversal_entry_id' => self::OWNER,
                'opening_balance_states.journal_entry_id' => self::OWNER,
                'asset_depreciation_entries.journal_entry_id' => self::OWNER,
                'bank_reconciliations.service_charge_entry_id' => self::OWNER,
                'bank_reconciliations.interest_earned_entry_id' => self::OWNER,
            ],
            'journal_lines' => [
                'bank_statement_lines.matched_journal_line_id' => self::RESET,
                'bank_reconciliations.marked_line_ids' => self::RESET, // in progress; a completed one blocks through BankReconciliationLockGuard
                'tax_return_lines.journal_line_id' => self::BLOCKS,
                'tax_returns.excluded_journal_line_ids' => self::BLOCKS, // filed; a draft's list is reset
            ],
        ];
    }

    /**
     * Polymorphic pointers, which can name any transaction: "table.type/id" =>
     * disposition.
     *
     * @return array<string, string>
     */
    public static function polymorphic(): array
    {
        return [
            'journal_entries.source_type/source_id' => self::OWNER,
            'stock_movements.source_type/source_id' => self::OWNED,
            'attachments.attachable_type/attachable_id' => self::OWNED,
            'edit_locks.lockable_type/lockable_id' => self::OWNED,
            'assets.source_type/source_id' => self::BLOCKS,
            'tax_return_lines.source_type/source_id' => self::BLOCKS,
            'accounting_audit_logs.auditable_type/auditable_id' => self::HISTORY,
        ];
    }
}
