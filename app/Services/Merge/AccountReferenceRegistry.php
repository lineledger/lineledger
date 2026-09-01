<?php

namespace App\Services\Merge;

/**
 * Authoritative list of every database column that references `accounts.id`,
 * for the account-merge feature.
 *
 * Semantics: these are REPOINT lists. When account B (loser) is merged into
 * account A (survivor), the merge action runs, for every entry in columns():
 *
 *     UPDATE {table} SET {column} = survivor_id WHERE {column} = loser_id
 *
 * Notes:
 *   - `accounts.parent_id` IS included — the loser's child accounts re-parent
 *     to the survivor.
 *   - `accounting_audit_logs` is immutable, hash-chained history. Its rows are
 *     never repointed (see excludedColumns()); the merge instead records its
 *     own `account.merged` audit event.
 *   - A handful of columns sit under unique indexes that include the account
 *     id (e.g. budget_lines, report_group_account_maps, cheques' cheque_no
 *     uniqueness per bank account). The merge action is responsible for
 *     resolving any collisions before the blind UPDATE.
 *   - Attachments: the `attachments` morph (attachable_type/attachable_id)
 *     cannot point at accounts — Account has no attachments() relation and no
 *     code path attaches to it — so this registry has no morphTables().
 *
 * Guarded by tests/Unit/Merge/MergeReferenceRegistryTest.php: every live-schema
 * column matching `account_id` / `*_account_id`, and every FK targeting
 * `accounts`, must appear in columns() or excludedColumns().
 */
final class AccountReferenceRegistry
{
    /**
     * Every column to repoint from the loser to the survivor account.
     *
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            // Self-FK: the loser's children re-parent to the survivor.
            ['table' => 'accounts', 'column' => 'parent_id'],

            // GL — the heart of the merge: posted activity moves wholesale.
            ['table' => 'journal_lines', 'column' => 'account_id'],

            // Company-level defaults (FK-less integer columns).
            ['table' => 'companies', 'column' => 'default_inventory_asset_account_id'],
            ['table' => 'companies', 'column' => 'default_cogs_account_id'],
            ['table' => 'companies', 'column' => 'exchange_gain_loss_account_id'],
            ['table' => 'companies', 'column' => 'unrealized_gain_loss_account_id'],

            // Core dimensions.
            ['table' => 'company_currencies', 'column' => 'ar_account_id'],
            ['table' => 'company_currencies', 'column' => 'ap_account_id'],
            ['table' => 'company_currencies', 'column' => 'gain_loss_account_id'],
            ['table' => 'tax_agencies', 'column' => 'payable_account_id'],
            // FK-less integer defaults on contacts.
            ['table' => 'contacts', 'column' => 'default_income_account_id'],
            ['table' => 'contacts', 'column' => 'default_expense_account_id'],
            ['table' => 'items', 'column' => 'income_account_id'],
            ['table' => 'items', 'column' => 'expense_account_id'],
            ['table' => 'items', 'column' => 'inventory_asset_account_id'],
            ['table' => 'items', 'column' => 'cogs_account_id'],
            ['table' => 'membership_levels', 'column' => 'revenue_account_id'],

            // Sales documents.
            ['table' => 'invoice_settings', 'column' => 'default_sales_account_id'],
            ['table' => 'invoice_lines', 'column' => 'account_id'],
            ['table' => 'estimate_lines', 'column' => 'account_id'],
            ['table' => 'sales_order_lines', 'column' => 'account_id'],
            ['table' => 'credit_memo_lines', 'column' => 'account_id'],
            ['table' => 'customer_receipts', 'column' => 'deposit_to_account_id'],
            ['table' => 'sales_receipts', 'column' => 'deposit_to_account_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'account_id'],
            ['table' => 'deposits', 'column' => 'bank_account_id'],
            ['table' => 'deposit_lines', 'column' => 'account_id'],
            ['table' => 'donation_receipts', 'column' => 'revenue_account_id'],
            ['table' => 'donation_receipts', 'column' => 'debit_account_id'],
            ['table' => 'donations', 'column' => 'deposit_to_account_id'],
            ['table' => 'donations', 'column' => 'revenue_account_id'],
            ['table' => 'donations', 'column' => 'deferred_account_id'],
            ['table' => 'grants', 'column' => 'deposit_to_account_id'],
            ['table' => 'grants', 'column' => 'deferred_account_id'],
            ['table' => 'grants', 'column' => 'revenue_account_id'],

            // Purchase documents.
            ['table' => 'bill_lines', 'column' => 'account_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'account_id'],
            ['table' => 'bill_payments', 'column' => 'paid_from_account_id'],
            ['table' => 'cheques', 'column' => 'bank_account_id'],
            ['table' => 'cheque_lines', 'column' => 'account_id'],
            ['table' => 'purchase_order_lines', 'column' => 'account_id'],
            // Money-out expenses paid straight from a bank/credit account.
            ['table' => 'expenses', 'column' => 'payment_account_id'],
            ['table' => 'expense_lines', 'column' => 'account_id'],
            ['table' => 'transfers', 'column' => 'from_account_id'],
            ['table' => 'transfers', 'column' => 'to_account_id'],

            // Tax.
            ['table' => 'tax_return_payments', 'column' => 'bank_account_id'],
            ['table' => 'tax_return_payments', 'column' => 'penalty_account_id'],
            ['table' => 'tax_return_payments', 'column' => 'interest_account_id'],
            ['table' => 'tax_return_payments', 'column' => 'commission_account_id'],

            // Banking.
            ['table' => 'bank_reconciliations', 'column' => 'account_id'],
            ['table' => 'bank_reconciliations', 'column' => 'service_charge_account_id'],
            ['table' => 'bank_reconciliations', 'column' => 'interest_earned_account_id'],
            ['table' => 'bank_import_profiles', 'column' => 'account_id'],
            ['table' => 'bank_statement_imports', 'column' => 'account_id'],
            ['table' => 'bank_statement_lines', 'column' => 'account_id'],
            ['table' => 'bank_statement_lines', 'column' => 'suggested_account_id'],
            // Categorization rules keep coding to the surviving account.
            ['table' => 'bank_rules', 'column' => 'action_account_id'],

            // Opening Balances workspace — draft trial-balance targets. Unique
            // on (opening_balance_state_id, account_id); MergeAccounts folds a
            // colliding pair into the survivor's row before this blind repoint.
            ['table' => 'opening_balance_rows', 'column' => 'account_id'],

            // Fixed assets.
            ['table' => 'asset_categories', 'column' => 'default_asset_account_id'],
            ['table' => 'asset_categories', 'column' => 'default_accumulated_depreciation_account_id'],
            ['table' => 'asset_categories', 'column' => 'default_depreciation_expense_account_id'],
            ['table' => 'assets', 'column' => 'asset_account_id'],
            ['table' => 'assets', 'column' => 'accumulated_depreciation_account_id'],
            ['table' => 'assets', 'column' => 'depreciation_expense_account_id'],

            // Payroll.
            ['table' => 'employee_payroll_profiles', 'column' => 'wage_expense_account_id'],
            ['table' => 'employee_recurring_items', 'column' => 'liability_account_id'],
            ['table' => 'employee_recurring_items', 'column' => 'expense_account_id'],
            ['table' => 'time_off_policies', 'column' => 'expense_account_id'],
            ['table' => 'time_off_policies', 'column' => 'liability_account_id'],
            ['table' => 'pay_runs', 'column' => 'bank_account_id'],
            ['table' => 'pay_run_line_earnings', 'column' => 'expense_account_id'],
            ['table' => 'pay_run_line_deductions', 'column' => 'liability_account_id'],
            ['table' => 'pay_run_line_contributions', 'column' => 'expense_account_id'],
            ['table' => 'pay_run_line_contributions', 'column' => 'liability_account_id'],
            ['table' => 'pay_run_line_manual_earnings', 'column' => 'expense_account_id'],
            ['table' => 'pay_run_line_accruals', 'column' => 'expense_account_id'],
            ['table' => 'pay_run_line_accruals', 'column' => 'liability_account_id'],
            ['table' => 'payroll_cheques', 'column' => 'bank_account_id'],
            ['table' => 'payroll_remittances', 'column' => 'bank_account_id'],

            // Recurring templates.
            ['table' => 'recurring_document_lines', 'column' => 'account_id'],
            ['table' => 'recurring_journal_entry_lines', 'column' => 'account_id'],

            // Document & journal-entry templates (reusable line blueprints).
            ['table' => 'invoice_template_lines', 'column' => 'account_id'],
            ['table' => 'journal_entry_template_lines', 'column' => 'account_id'],

            // Budgeting + cross-company reporting.
            ['table' => 'budget_lines', 'column' => 'account_id'],
            ['table' => 'report_group_account_maps', 'column' => 'account_id'],
        ];
    }

    /**
     * Columns whose name matches the `account_id` pattern (or whose FK targets
     * `accounts`) but that must NOT be repointed by a merge.
     *
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function excludedColumns(): array
    {
        return [
            [
                'table' => 'companies',
                'column' => 'stripe_account_id',
                'reason' => 'Stripe Connect account identifier string, not a reference to accounts.id',
            ],
            [
                'table' => 'accounting_audit_logs',
                'column' => 'auditable_id',
                'reason' => 'immutable hash-chained audit history; the auditable morph may point at the loser account but history is never rewritten — the merge records its own account.merged event',
            ],
        ];
    }
}
