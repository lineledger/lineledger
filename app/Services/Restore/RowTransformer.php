<?php

namespace App\Services\Restore;

use App\Services\Backup\ReferencedUsersExporter;

/**
 * Transforms one decoded `.jsonl` row from the bundle into the row payload the
 * orchestrator will hand to `DB::table(...)->insertGetId(...)` (parent tables)
 * or `DB::table(...)->insert($chunk)` (children).
 *
 * Responsibilities, applied in this order:
 *
 *  1. Capture and drop the row's `id` so the target instance can auto-increment.
 *     The caller re-registers the mapping `old_id => new_id` on `IdMapper`
 *     after the insert returns.
 *  2. Swap `company_id` to the freshly-created Company's id on every row that
 *     has the column. Child rows (e.g. `journal_lines`) often don't.
 *  3. Remap user-flavoured columns (`*_user_id` plus the exact set the
 *     exporter scans: `created_by`, `uploaded_by_id`, `invited_by`, `user_id`)
 *     through the email-match map built by {@see UserRemapBuilder}. Unknown
 *     ids fall back to the importing user, mirroring the exporter contract.
 *  4. Remap parent foreign keys via {@see IdMapper} using the per-table
 *     {@see self::PARENT_FK_MAP}. Unknown ids are left intact (the orchestrator
 *     surfaces orphaned references; we never crash on missing parents).
 *  5. Polymorphic `*_type` / `*_id` pairs (`journal_entries.source`, etc.) are
 *     translated by resolving the FQCN to its Eloquent table and looking up
 *     the new id in `IdMapper`. Unknown types are passed through untouched.
 *  6. Per-table quirks (`companies`, `attachments`, `company_api_keys`,
 *     `company_members`) are applied last.
 *
 * JSON columns: arrays remain as arrays in the returned row. The orchestrator
 * `json_encode()`s array values right before the `DB::table()->insert()` call.
 * Keeping that encoding out of here keeps the transformer pure and easy to
 * snapshot in tests.
 */
final class RowTransformer
{
    /**
     * Parent FK columns to translate via `IdMapper` for each table.
     *
     * Confirmed against the migrations + `#[Fillable]` attributes on the
     * Eloquent models — anything missing here either doesn't exist as a column
     * or is handled elsewhere (polymorphic pair, user_id remap, special case).
     *
     * @var array<string, array<string, string>>
     *
     * @internal Exposed for the arch test that asserts every FK references a
     *           table earlier in BackupTableRegistry.
     */
    public const PARENT_FK_MAP = [
        // === Child / line tables ===
        'journal_lines' => [
            'journal_entry_id' => 'journal_entries',
            'account_id' => 'accounts',
            'contact_id' => 'contacts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
            'bank_reconciliation_id' => 'bank_reconciliations',
        ],
        'journal_entry_templates' => [],
        'journal_entry_template_lines' => [
            'journal_entry_template_id' => 'journal_entry_templates',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'invoice_lines' => [
            'invoice_id' => 'invoices',
            'item_id' => 'items',
            'sales_order_line_id' => 'sales_order_lines',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'reminder_tiers' => [],
        'invoice_reminder_logs' => [
            'invoice_id' => 'invoices',
            'reminder_tier_id' => 'reminder_tiers',
        ],
        'invoice_payment_requests' => [
            'invoice_id' => 'invoices',
        ],
        'invoice_templates' => [],
        'invoice_template_lines' => [
            'invoice_template_id' => 'invoice_templates',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
        ],
        'estimate_lines' => [
            'estimate_id' => 'estimates',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'sales_order_lines' => [
            'sales_order_id' => 'sales_orders',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'credit_memo_lines' => [
            'credit_memo_id' => 'credit_memos',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'bill_lines' => [
            'bill_id' => 'bills',
            'item_id' => 'items',
            'purchase_order_line_id' => 'purchase_order_lines',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'purchase_order_lines' => [
            'purchase_order_id' => 'purchase_orders',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'vendor_credit_lines' => [
            'vendor_credit_id' => 'vendor_credits',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'cheque_lines' => [
            'cheque_id' => 'cheques',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'expense_lines' => [
            'expense_id' => 'expenses',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'tax_return_lines' => [
            'tax_return_id' => 'tax_returns',
            'journal_line_id' => 'journal_lines',
            'journal_entry_id' => 'journal_entries',
        ],
        'stock_adjustment_lines' => [
            'stock_adjustment_id' => 'stock_adjustments',
            'item_id' => 'items',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'receipt_applications' => [
            'customer_receipt_id' => 'customer_receipts',
            'invoice_id' => 'invoices',
        ],
        'sales_receipt_lines' => [
            'sales_receipt_id' => 'sales_receipts',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'bill_payment_applications' => [
            'bill_payment_id' => 'bill_payments',
            'bill_id' => 'bills',
        ],
        'deposit_lines' => [
            'deposit_id' => 'deposits',
            'customer_receipt_id' => 'customer_receipts',
            'sales_receipt_id' => 'sales_receipts',
            'account_id' => 'accounts',
            'contact_id' => 'contacts',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'recurring_document_lines' => [
            'recurring_document_id' => 'recurring_documents',
            'item_id' => 'items',
            'account_id' => 'accounts',
            'tax_code_id' => 'tax_codes',
            'secondary_tax_code_id' => 'tax_codes',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'recurring_journal_entry_lines' => [
            'recurring_journal_entry_id' => 'recurring_journal_entries',
            'account_id' => 'accounts',
            'contact_id' => 'contacts',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],

        // === Parent / header tables ===
        'invoices' => [
            'contact_id' => 'contacts',
            'sales_rep_id' => 'contacts',
            'sales_order_id' => 'sales_orders',
            'terms_id' => 'payment_terms',
            'journal_entry_id' => 'journal_entries',
            'recurring_document_id' => 'recurring_documents',
            'member_id' => 'members',
            'form_style_id' => 'form_styles',
        ],
        'estimates' => [
            'contact_id' => 'contacts',
            'sales_rep_id' => 'contacts',
            'terms_id' => 'payment_terms',
            'converted_invoice_id' => 'invoices',
            'converted_sales_order_id' => 'sales_orders',
        ],
        'sales_orders' => [
            'contact_id' => 'contacts',
            'sales_rep_id' => 'contacts',
            'terms_id' => 'payment_terms',
        ],
        'credit_memos' => [
            'contact_id' => 'contacts',
            'sales_rep_id' => 'contacts',
            'journal_entry_id' => 'journal_entries',
        ],
        'customer_receipts' => [
            'contact_id' => 'contacts',
            'credit_memo_id' => 'credit_memos',
            'deposit_to_account_id' => 'accounts',
            'payment_method_id' => 'payment_methods',
            'journal_entry_id' => 'journal_entries',
        ],
        'sales_receipts' => [
            'contact_id' => 'contacts',
            'deposit_to_account_id' => 'accounts',
            'payment_method_id' => 'payment_methods',
            'journal_entry_id' => 'journal_entries',
        ],
        'donation_receipts' => [
            'contact_id' => 'contacts',
            'revenue_account_id' => 'accounts',
            'debit_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
            'customer_receipt_id' => 'customer_receipts',
            'reissued_from_id' => 'donation_receipts',
        ],
        'donations' => [
            'contact_id' => 'contacts',
            'fund_id' => 'funds',
            'deposit_to_account_id' => 'accounts',
            'revenue_account_id' => 'accounts',
            'deferred_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
            'donation_receipt_id' => 'donation_receipts',
        ],
        'grants' => [
            'funder_contact_id' => 'contacts',
            'fund_id' => 'funds',
            'deposit_to_account_id' => 'accounts',
            'deferred_account_id' => 'accounts',
            'revenue_account_id' => 'accounts',
            'award_journal_entry_id' => 'journal_entries',
        ],
        'grant_recognitions' => [
            'grant_id' => 'grants',
            'journal_entry_id' => 'journal_entries',
        ],
        'deposits' => [
            'bank_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
        ],
        'bills' => [
            'contact_id' => 'contacts',
            'purchase_order_id' => 'purchase_orders',
            'terms_id' => 'payment_terms',
            'journal_entry_id' => 'journal_entries',
            'recurring_document_id' => 'recurring_documents',
        ],
        'purchase_orders' => [
            'contact_id' => 'contacts',
            'terms_id' => 'payment_terms',
        ],
        'vendor_credits' => [
            'contact_id' => 'contacts',
            'journal_entry_id' => 'journal_entries',
        ],
        'bill_payments' => [
            'contact_id' => 'contacts',
            'paid_from_account_id' => 'accounts',
            'payment_method_id' => 'payment_methods',
            'journal_entry_id' => 'journal_entries',
        ],
        'cheques' => [
            'bank_account_id' => 'accounts',
            'payee_contact_id' => 'contacts',
            'credit_memo_id' => 'credit_memos',
            'journal_entry_id' => 'journal_entries',
        ],
        'expenses' => [
            'payment_account_id' => 'accounts',
            'payment_method_id' => 'payment_methods',
            'payee_contact_id' => 'contacts',
            'journal_entry_id' => 'journal_entries',
        ],
        'transfers' => [
            'from_account_id' => 'accounts',
            'to_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
        ],
        'accounts' => [
            'parent_id' => 'accounts',
            'report_section_id' => 'report_sections',
        ],
        'contacts' => [
            // Self-referential parent (sub-customers); lower ids insert first.
            'parent_id' => 'contacts',
            'default_terms_id' => 'payment_terms',
            'default_tax_code_id' => 'tax_codes',
            'default_income_account_id' => 'accounts',
            'default_expense_account_id' => 'accounts',
            'preferred_payment_method_id' => 'payment_methods',
        ],
        'item_categories' => [
            // Self-referential parent (like accounts.parent_id): parent rows carry
            // lower ids and are inserted first, so IdMapper has them in time.
            'parent_id' => 'item_categories',
        ],
        'items' => [
            'item_category_id' => 'item_categories',
            'income_account_id' => 'accounts',
            'expense_account_id' => 'accounts',
            'inventory_asset_account_id' => 'accounts',
            'cogs_account_id' => 'accounts',
            'default_tax_code_id' => 'tax_codes',
            'default_secondary_tax_code_id' => 'tax_codes',
        ],
        'item_components' => [
            'item_id' => 'items',
            'component_item_id' => 'items',
        ],
        'membership_levels' => [
            'revenue_account_id' => 'accounts',
            'default_terms_id' => 'payment_terms',
            'default_tax_code_id' => 'tax_codes',
        ],
        'members' => [
            'contact_id' => 'contacts',
            'membership_level_id' => 'membership_levels',
        ],
        'tax_codes' => [
            'agency_id' => 'tax_agencies',
        ],
        'tax_agencies' => [
            'payable_account_id' => 'accounts',
        ],
        'tax_returns' => [
            'tax_agency_id' => 'tax_agencies',
        ],
        'tax_return_payments' => [
            'tax_return_id' => 'tax_returns',
            'bank_account_id' => 'accounts',
            'payment_method_id' => 'payment_methods',
            'penalty_account_id' => 'accounts',
            'interest_account_id' => 'accounts',
            'commission_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
        ],
        'bank_reconciliations' => [
            'account_id' => 'accounts',
            'service_charge_account_id' => 'accounts',
            'service_charge_entry_id' => 'journal_entries',
            'interest_earned_account_id' => 'accounts',
            'interest_earned_entry_id' => 'journal_entries',
        ],
        'bank_rules' => [
            'action_account_id' => 'accounts',
        ],
        'journal_entries' => [
            'recurring_journal_entry_id' => 'recurring_journal_entries',
            'reverses_entry_id' => 'journal_entries',
            'reversed_by_entry_id' => 'journal_entries',
        ],
        'accounting_audit_logs' => [
            'journal_entry_id' => 'journal_entries',
            'api_key_id' => 'company_api_keys',
        ],
        'stock_movements' => [
            'item_id' => 'items',
            'journal_entry_id' => 'journal_entries',
            'reversal_of_movement_id' => 'stock_movements',
        ],
        'stock_layers' => [
            'item_id' => 'items',
            'stock_movement_id' => 'stock_movements',
        ],
        'stock_adjustments' => [
            'journal_entry_id' => 'journal_entries',
        ],
        'currency_revaluations' => [
            'journal_entry_id' => 'journal_entries',
            'reversal_entry_id' => 'journal_entries',
        ],
        'company_currencies' => [
            'ar_account_id' => 'accounts',
            'ap_account_id' => 'accounts',
            'gain_loss_account_id' => 'accounts',
        ],
        'exchange_rates' => [],
        'invoice_settings' => [
            'default_sales_account_id' => 'accounts',
        ],
        'form_styles' => [],
        'memorized_reports' => [
            'memorized_report_group_id' => 'memorized_report_groups',
        ],
        'memorized_report_groups' => [],
        // user_id is remapped by the USER_ID_EXACT mechanism, not listed here.
        'report_email_schedules' => [
            'memorized_report_id' => 'memorized_reports',
            'memorized_report_group_id' => 'memorized_report_groups',
        ],
        'report_favorites' => [],
        'report_packages' => [],
        'report_package_items' => [
            'report_package_id' => 'report_packages',
            'memorized_report_id' => 'memorized_reports',
        ],
        'nav_preferences' => [],
        // user_id is remapped by the generic USER_ID_EXACT pass, like nav_preferences.
        'grid_preferences' => [],
        'report_sections' => [],
        'company_members' => [],
        'company_invitations' => [],
        'company_api_keys' => [],
        // Document inbox. created_by_user_id is remapped by the generic `*_user_id`
        // pass; promoted_document_id is a polymorphic (promoted_document_type)
        // pointer to bills/expenses, intentionally left un-remapped.
        'inbox_items' => [
            'attachment_id' => 'attachments',
            'suggested_contact_id' => 'contacts',
        ],
        // Agentic-MCP proposals. user_id is remapped by the generic `*_user_id`
        // pass; company_id by the generic company swap.
        'mcp_write_proposals' => [
            'company_api_key_id' => 'company_api_keys',
            'confirmed_journal_entry_id' => 'journal_entries',
        ],
        'asset_categories' => [
            'default_asset_account_id' => 'accounts',
            'default_accumulated_depreciation_account_id' => 'accounts',
            'default_depreciation_expense_account_id' => 'accounts',
        ],
        'assets' => [
            'asset_category_id' => 'asset_categories',
            'asset_account_id' => 'accounts',
            'accumulated_depreciation_account_id' => 'accounts',
            'depreciation_expense_account_id' => 'accounts',
        ],
        'asset_depreciation_entries' => [
            'asset_id' => 'assets',
            'journal_entry_id' => 'journal_entries',
        ],
        'recurring_documents' => [
            'contact_id' => 'contacts',
            'terms_id' => 'payment_terms',
        ],
        'document_folders' => [
            // Self-referential parent (like accounts.parent_id): parent rows carry
            // lower ids and are inserted first, so IdMapper has them in time.
            'parent_folder_id' => 'document_folders',
            // created_by_member_id is remapped in applyTableQuirks(): the owner
            // membership is skipped on import, so an unmapped value must become
            // null rather than violating the company_members FK.
        ],
        'recurring_journal_entries' => [],
        'payment_terms' => [],
        'payment_methods' => [],
        'classifications' => [],
        'locations' => [],
        'funds' => [],

        // === Payroll ===
        'payroll_schedules' => [],
        'employee_payroll_profiles' => [
            'contact_id' => 'contacts',
            'payroll_schedule_id' => 'payroll_schedules',
            'wage_expense_account_id' => 'accounts',
            'class_id' => 'classifications',
            'location_id' => 'locations',
        ],
        'employee_recurring_items' => [
            'employee_payroll_profile_id' => 'employee_payroll_profiles',
            'liability_account_id' => 'accounts',
            'expense_account_id' => 'accounts',
        ],
        'pay_runs' => [
            'payroll_schedule_id' => 'payroll_schedules',
            'bank_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
        ],
        'pay_run_lines' => [
            'pay_run_id' => 'pay_runs',
            'contact_id' => 'contacts',
            'employee_payroll_profile_id' => 'employee_payroll_profiles',
        ],
        'pay_run_line_earnings' => [
            'pay_run_line_id' => 'pay_run_lines',
            'expense_account_id' => 'accounts',
            'class_id' => 'classifications',
            'location_id' => 'locations',
        ],
        'pay_run_line_deductions' => [
            'pay_run_line_id' => 'pay_run_lines',
            'liability_account_id' => 'accounts',
        ],
        'pay_run_line_contributions' => [
            'pay_run_line_id' => 'pay_run_lines',
            'expense_account_id' => 'accounts',
            'liability_account_id' => 'accounts',
        ],
        'pay_run_line_manual_earnings' => [
            'pay_run_line_id' => 'pay_run_lines',
            'expense_account_id' => 'accounts',
        ],
        'pay_run_line_accruals' => [
            'pay_run_line_id' => 'pay_run_lines',
            'expense_account_id' => 'accounts',
            'liability_account_id' => 'accounts',
        ],
        'employee_accrual_balances' => [
            'employee_payroll_profile_id' => 'employee_payroll_profiles',
        ],
        'time_off_policies' => [
            'expense_account_id' => 'accounts',
            'liability_account_id' => 'accounts',
        ],
        'employee_time_off_policies' => [
            'employee_payroll_profile_id' => 'employee_payroll_profiles',
            'time_off_policy_id' => 'time_off_policies',
        ],
        'payroll_cheques' => [
            'pay_run_id' => 'pay_runs',
            'pay_run_line_id' => 'pay_run_lines',
            'bank_account_id' => 'accounts',
            'payee_contact_id' => 'contacts',
            'journal_entry_id' => 'journal_entries',
        ],
        'payroll_remittances' => [
            'bank_account_id' => 'accounts',
            'journal_entry_id' => 'journal_entries',
        ],
        // finalized_by_user_id is remapped by the generic `*_user_id` pass.
        'payroll_slip_filings' => [],
        'payroll_slip_filing_lines' => [
            'payroll_slip_filing_id' => 'payroll_slip_filings',
            'contact_id' => 'contacts',
        ],
        'workers_comp_settings' => [], // company_id only (handled by the company remap)
        // manager_decided_by_user_id / decided_by_user_id are remapped by the
        // generic `*_user_id` pass.
        'time_off_requests' => [
            'contact_id' => 'contacts',
            'time_off_policy_id' => 'time_off_policies',
        ],
        'time_entries' => [
            'contact_id' => 'contacts',
            'customer_id' => 'contacts',
            'item_id' => 'items',
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'pay_run_id' => 'pay_runs',
            'invoice_id' => 'invoices',
            'time_off_request_id' => 'time_off_requests',
        ],

        // `companies` row also references accounts (default_inventory_asset_account_id,
        // default_cogs_account_id, exchange_gain_loss_account_id,
        // unrealized_gain_loss_account_id). The orchestrator UPDATEs the freshly
        // created Company with the transformed row after all account ids exist,
        // so these are remapped here just like any other parent FK.
        'companies' => [
            'default_inventory_asset_account_id' => 'accounts',
            'default_cogs_account_id' => 'accounts',
            'exchange_gain_loss_account_id' => 'accounts',
            'unrealized_gain_loss_account_id' => 'accounts',
        ],

        // Polymorphic-host tables get their `*_id` member fields remapped via
        // the polymorphic block below — the `_type` column tells us which
        // logical table to look up. Listed here for completeness.
    ];

    /**
     * Polymorphic `(type, id)` column pairs that need to be looked up by
     * resolving the FQCN to its underlying Eloquent table and remapping the
     * id through {@see IdMapper}.
     *
     * @var array<string, array<int, array{type: string, id: string}>>
     */
    private const POLYMORPHIC_PAIRS = [
        'journal_entries' => [
            ['type' => 'source_type', 'id' => 'source_id'],
        ],
        'stock_movements' => [
            ['type' => 'source_type', 'id' => 'source_id'],
        ],
        'assets' => [
            ['type' => 'source_type', 'id' => 'source_id'],
        ],
        'attachments' => [
            ['type' => 'attachable_type', 'id' => 'attachable_id'],
        ],
        'accounting_audit_logs' => [
            ['type' => 'auditable_type', 'id' => 'auditable_id'],
        ],
    ];

    /**
     * Cross-cycle FK columns that cannot satisfy forward-topological order
     * in {@see BackupTableRegistry::tables()} — they reference a table that
     * comes LATER in the import sequence (e.g. estimates.converted_invoice_id
     * → invoices, which doesn't exist yet when estimates are imported).
     *
     * For these we strip the value on the primary insert and report it back
     * via the transform result's `deferred` key. The orchestrator collects
     * them and runs a post-loop `UPDATE` pass once IdMapper is populated.
     *
     * Columns NOT listed here are required to be ordering-safe — the
     * RegistryOrderingTest arch test enforces this.
     *
     * The `companies` row's own deferred account FKs are handled separately
     * in CompanyImporter::import() (the shell-create + post-loop re-update
     * predates this map), so they're not listed here.
     *
     * @var array<string, array<string, string>> childTable => [column => parentTableInIdMapper]
     */
    public const DEFERRED_FK_COLUMNS = [
        // accounts restore before tax_codes (tax_agencies.payable_account_id
        // FKs accounts), so the account-level default tax code is circular and
        // must be patched in after the table loop.
        'accounts' => [
            'default_tax_code_id' => 'tax_codes',
        ],
        'journal_entries' => [
            'recurring_journal_entry_id' => 'recurring_journal_entries',
        ],
        'journal_lines' => [
            'bank_reconciliation_id' => 'bank_reconciliations',
        ],
        'estimates' => [
            'converted_invoice_id' => 'invoices',
            'converted_sales_order_id' => 'sales_orders',
        ],
        'invoices' => [
            'recurring_document_id' => 'recurring_documents',
        ],
        'bills' => [
            'recurring_document_id' => 'recurring_documents',
        ],
        'members' => [
            'recurring_document_id' => 'recurring_documents',
        ],
        'donation_receipts' => [
            'donation_id' => 'donations',
        ],
        'accounting_audit_logs' => [
            'api_key_id' => 'company_api_keys',
        ],
    ];

    /**
     * Exact column names treated as `users.id` references. Mirrors
     * {@see ReferencedUsersExporter::USER_ID_EXACT} so the
     * export-side discovery and import-side remap stay in lockstep.
     *
     * @var list<string>
     */
    private const USER_ID_EXACT = ['created_by', 'uploaded_by_id', 'invited_by', 'user_id'];

    /**
     * @param  array<int, int>  $userIdMap  oldUserId => newUserId, including fallback entries.
     */
    public function __construct(
        private readonly IdMapper $idMapper,
        private readonly array $userIdMap,
        private readonly int $importingUserId,
        private readonly int $newCompanyId,
    ) {}

    /**
     * Transform a single row read from `data/{table}.jsonl` into the payload
     * the orchestrator will insert.
     *
     * @param  array<string, mixed>  $row
     * @return array{row: array<string, mixed>, old_id: ?int, skip: bool, deferred: array<string, int>}
     */
    public function transform(string $table, array $row): array
    {
        // 1. Capture and drop the row's `id` — the target DB will auto-increment.
        $oldId = $this->extractOldId($row);

        // 2. Swap `company_id`.
        if (array_key_exists('company_id', $row)) {
            $row['company_id'] = $this->newCompanyId;
        }

        // 3. User id remap.
        $row = $this->remapUserColumns($row);

        // 4. Parent FK remap.
        $row = $this->remapParentForeignKeys($table, $row);

        // 5. Polymorphic pair remap.
        $row = $this->remapPolymorphicPairs($table, $row);

        // 6. Strip cross-cycle FKs for later post-pass UPDATE.
        $deferred = $this->extractDeferredForeignKeys($table, $row);

        // 7. Per-table quirks (and `skip` decisions).
        $result = $this->applyTableQuirks($table, $row, $oldId);
        $result['deferred'] = $deferred;

        return $result;
    }

    /**
     * Strip and return any DEFERRED_FK_COLUMNS values for this row. The
     * orchestrator will UPDATE them in after the main table loop populates
     * IdMapper for the target tables.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int> [columnName => oldParentId] — both passed by reference, so $row is mutated.
     */
    private function extractDeferredForeignKeys(string $table, array &$row): array
    {
        $deferredColumns = self::DEFERRED_FK_COLUMNS[$table] ?? [];
        if ($deferredColumns === []) {
            return [];
        }

        $captured = [];
        foreach ($deferredColumns as $column => $_parentTable) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            $row[$column] = null;

            if (is_int($value) && $value > 0) {
                $captured[$column] = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $captured[$column] = (int) $value;
            }
        }

        return $captured;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractOldId(array &$row): ?int
    {
        if (! array_key_exists('id', $row)) {
            return null;
        }

        $oldId = $row['id'];
        unset($row['id']);

        return is_int($oldId) || (is_string($oldId) && ctype_digit($oldId))
            ? (int) $oldId
            : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function remapUserColumns(array $row): array
    {
        foreach ($row as $column => $value) {
            if (! $this->isUserColumn($column)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                // Defensive: leave non-integer payloads alone.
                continue;
            }

            $oldUserId = (int) $value;

            $row[$column] = $this->userIdMap[$oldUserId] ?? $this->importingUserId;
        }

        return $row;
    }

    private function isUserColumn(string $column): bool
    {
        if (in_array($column, self::USER_ID_EXACT, true)) {
            return true;
        }

        return str_ends_with($column, '_user_id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function remapParentForeignKeys(string $table, array $row): array
    {
        $fkMap = self::PARENT_FK_MAP[$table] ?? [];

        foreach ($fkMap as $column => $targetTable) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($value === null) {
                continue;
            }

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                continue;
            }

            $newId = $this->idMapper->get($targetTable, (int) $value);

            if ($newId !== null) {
                $row[$column] = $newId;
            }
            // If the parent id wasn't recorded, leave the column untouched.
            // The orchestrator's post-insert reconciliation flags orphans.
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function remapPolymorphicPairs(string $table, array $row): array
    {
        $pairs = self::POLYMORPHIC_PAIRS[$table] ?? [];

        foreach ($pairs as $pair) {
            $typeCol = $pair['type'];
            $idCol = $pair['id'];

            if (! array_key_exists($typeCol, $row) || ! array_key_exists($idCol, $row)) {
                continue;
            }

            $type = $row[$typeCol];
            $id = $row[$idCol];

            if ($type === null || $id === null) {
                continue;
            }

            if (! is_string($type) || ! class_exists($type)) {
                // Unrecognized polymorphic class (renamed model, removed feature, etc.).
                // Leave both columns alone; orchestrator will surface the orphan.
                continue;
            }

            $targetTable = $this->resolveMorphTable($type);

            if ($targetTable === null) {
                continue;
            }

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $newId = $this->idMapper->get($targetTable, (int) $id);

            if ($newId !== null) {
                $row[$idCol] = $newId;
            }
        }

        return $row;
    }

    /**
     * Resolve an Eloquent model FQCN to its table name without booting a real
     * instance against the DB. Returns null if the class can't be instantiated
     * cleanly (defensive — never lets an unknown class crash the import).
     */
    private function resolveMorphTable(string $fqcn): ?string
    {
        try {
            $instance = new $fqcn;
        } catch (\Throwable) {
            return null;
        }

        if (! method_exists($instance, 'getTable')) {
            return null;
        }

        $table = $instance->getTable();

        return is_string($table) && $table !== '' ? $table : null;
    }

    /**
     * Apply per-table quirks AFTER the generic transformations have run.
     *
     * @param  array<string, mixed>  $row
     * @return array{row: array<string, mixed>, old_id: ?int, skip: bool}
     */
    private function applyTableQuirks(string $table, array $row, ?int $oldId): array
    {
        $skip = false;

        switch ($table) {
            case 'companies':
                // The new Company shell already exists. The orchestrator uses
                // this row to UPDATE that shell (settings, brand, addresses,
                // feature flags, account ids), then re-attaches the logo after
                // the file pass. Strip the columns that must not round-trip:
                //   - `slug`: regenerated for the new tenant.
                //   - `logo_path` / `document_logo_path`: rewritten by
                //     AttachmentImporter after the file copy.
                //   - `stripe_*`: secrets that should never cross instances.
                unset($row['slug'], $row['stripe_account_id'], $row['stripe_connected_at']);
                $row['logo_path'] = null;
                $row['document_logo_path'] = null;

                // Defensive: strip anything starting with `stripe_` so a new
                // Stripe-related column added later doesn't sneak through.
                foreach (array_keys($row) as $key) {
                    if (str_starts_with($key, 'stripe_')) {
                        unset($row[$key]);
                    }
                }
                break;

            case 'attachments':
                // `path` is intentionally preserved as the bundle-relative path
                // (e.g. `files/attachments/...`). The orchestrator's
                // AttachmentImporter copies the blob to the target disk after
                // the row is inserted and then runs a single
                // `UPDATE attachments SET path = ?` to swap it to the disk-
                // relative final path.
                // Polymorphic remap already handled above.
                break;

            case 'company_api_keys':
                // Imported API keys are unusable until the user mints a new
                // secret — only the hashed token + last_four crossed the wire
                // and the hash isn't reversible. Null any plaintext token
                // columns defensively in case a future schema gains one.
                foreach (['token', 'token_hash', 'plaintext_token'] as $secretColumn) {
                    if (array_key_exists($secretColumn, $row)) {
                        $row[$secretColumn] = null;
                    }
                }
                break;

            case 'document_folders':
                // Remap the creator membership through IdMapper. The owner's
                // membership is skipped on import, so a value that doesn't map
                // becomes null (creator linkage is lost; Owner/Admin still manage)
                // rather than violating the nullable company_members FK.
                if (array_key_exists('created_by_member_id', $row) && $row['created_by_member_id'] !== null) {
                    $oldMemberId = $row['created_by_member_id'];

                    $row['created_by_member_id'] = (is_int($oldMemberId) || (is_string($oldMemberId) && ctype_digit($oldMemberId)))
                        ? $this->idMapper->get('company_members', (int) $oldMemberId)
                        : null;
                }

                // The viewer allow-list holds company_members.id values. Remap
                // each through IdMapper so shared-folder permissions survive the
                // restore; drop ids that didn't map (removed members).
                if (isset($row['viewer_member_ids']) && is_array($row['viewer_member_ids'])) {
                    $remapped = [];

                    foreach ($row['viewer_member_ids'] as $memberId) {
                        if (! is_int($memberId) && ! (is_string($memberId) && ctype_digit($memberId))) {
                            continue;
                        }

                        $newId = $this->idMapper->get('company_members', (int) $memberId);

                        if ($newId !== null) {
                            $remapped[] = $newId;
                        }
                    }

                    $row['viewer_member_ids'] = $remapped === [] ? null : $remapped;
                }
                break;

            case 'company_members':
                // The source company's owner row arrives with `user_id` already
                // remapped to either the matched target user or the importer
                // fallback. If we'd map this row to the importing user, the
                // orchestrator's final Owner-Membership insert would duplicate
                // it. Skip instead.
                $remappedUserId = $row['user_id'] ?? null;

                if (is_int($remappedUserId) && $remappedUserId === $this->importingUserId) {
                    $skip = true;
                }
                break;
        }

        return [
            'row' => $row,
            'old_id' => $oldId,
            'skip' => $skip,
        ];
    }
}
