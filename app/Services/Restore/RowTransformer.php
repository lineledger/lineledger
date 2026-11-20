<?php

namespace App\Services\Restore;

use App\Actions\Documents\PromoteInboxItem;
use App\Enums\McpProposalStatus;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\ReferencedUsersExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

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
 *     {@see self::PARENT_FK_MAP}.
 *  5. Polymorphic `*_type` / `*_id` pairs (`journal_entries.source`, etc.) are
 *     translated by resolving the FQCN to its Eloquent table and looking up
 *     the new id in `IdMapper`. Unknown types are passed through untouched.
 *
 *     A reference (4 or 5) whose row isn't in `IdMapper` yet is one of two
 *     things. If its table restores at or after this one (a self-reference such
 *     as `reversed_by_entry_id`, or a source document restored later), it is
 *     **deferred**: inserted as null and patched in by the orchestrator once
 *     every table is in. If its table has already been restored, the row it
 *     names isn't in the bundle: it is an **orphan**, inserted as null and
 *     reported. A source id is never copied through, since on this instance it
 *     names another organization's row, or none.
 *  6. Per-table quirks (`companies`, `attachments`, `company_api_keys`,
 *     `company_invitations`, `customer_receipts`, `mcp_write_proposals`,
 *     `document_folders`, `company_members`) are applied last. They include
 *     the handling {@see self::GLOBALLY_UNIQUE_COLUMNS} lists, so a copy
 *     restored next to its source never repeats an instance-wide unique value.
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
            'contact_id' => 'contacts',
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
        'tax_return_adjustments' => [
            'tax_return_id' => 'tax_returns',
            'account_id' => 'accounts',
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
            // Self-referential: a refund names the payment whose credit it
            // returns. The refund is always created later, so lower ids insert first.
            'refunded_receipt_id' => 'customer_receipts',
            'from_invoice_id' => 'invoices',
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
            'refunded_receipt_id' => 'customer_receipts',
            'from_invoice_id' => 'invoices',
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
            'adjustment_journal_entry_id' => 'journal_entries',
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
            'action_contact_id' => 'contacts',
        ],
        'journal_entries' => [
            'recurring_journal_entry_id' => 'recurring_journal_entries',
            'reverses_entry_id' => 'journal_entries',
            'reversed_by_entry_id' => 'journal_entries',
        ],
        // A deleted transaction's record: the only live reference is the bank
        // account whose cheque numbers it reserves (original_id is history).
        'deleted_transactions' => [
            'bank_account_id' => 'accounts',
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
        // Opening Balances workspace. created_by_user_id / updated_by_user_id
        // are remapped by the generic `*_user_id` pass. States restore after
        // journal_entries; rows restore after states + accounts (registry order).
        'opening_balance_states' => [
            'journal_entry_id' => 'journal_entries',
        ],
        'opening_balance_rows' => [
            'opening_balance_state_id' => 'opening_balance_states',
            'account_id' => 'accounts',
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
        // Deadlines: assigned_user_id / completed_by_user_id / created_by_user_id
        // and the settings + log user_id are remapped by the generic user pass.
        'deadlines' => [
            'tax_agency_id' => 'tax_agencies',
        ],
        'deadline_occurrences' => [
            'deadline_id' => 'deadlines',
        ],
        'deadline_notification_settings' => [],
        'deadline_notification_logs' => [
            'deadline_occurrence_id' => 'deadline_occurrences',
        ],
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
        // user_id is remapped by the generic USER_ID_EXACT pass, like grid_preferences.
        'user_preferences' => [],
        'report_sections' => [],
        'company_members' => [],
        // company_member_id is remapped in applyTableQuirks: a member skipped on
        // import must take its assignments with it, not keep a stale id.
        'company_member_roles' => ['role_id' => 'roles'],
        'company_invitations' => [],
        'company_invitation_roles' => ['company_invitation_id' => 'company_invitations', 'role_id' => 'roles'],
        'roles' => [],
        'role_permissions' => ['role_id' => 'roles'],
        'company_api_keys' => [],
        // Document inbox. created_by_user_id is remapped by the generic `*_user_id`
        // pass; promoted_document_id is a polymorphic (promoted_document_type)
        // pointer to bills/expenses, intentionally left un-remapped.
        'inbox_items' => [
            'attachment_id' => 'attachments',
            'suggested_contact_id' => 'contacts',
        ],
        'budgets' => [
            'class_id' => 'classifications',
            'location_id' => 'locations',
            'fund_id' => 'funds',
        ],
        'budget_lines' => [
            'budget_id' => 'budgets',
            'account_id' => 'accounts',
        ],
        // Bank-feed imports. created_by_user_id is remapped by the generic
        // `*_user_id` pass.
        'bank_import_profiles' => [
            'account_id' => 'accounts',
        ],
        'bank_statement_imports' => [
            'account_id' => 'accounts',
            'bank_reconciliation_id' => 'bank_reconciliations',
            'bank_import_profile_id' => 'bank_import_profiles',
            'attachment_id' => 'attachments',
        ],
        'bank_statement_lines' => [
            'bank_statement_import_id' => 'bank_statement_imports',
            'account_id' => 'accounts',
            'matched_journal_line_id' => 'journal_lines',
            'created_journal_entry_id' => 'journal_entries',
            'suggested_account_id' => 'accounts',
            'suggested_contact_id' => 'contacts',
            'suggested_bill_id' => 'bills',
            'suggested_tax_code_id' => 'tax_codes',
            'suggested_secondary_tax_code_id' => 'tax_codes',
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
            'fund_id' => 'funds',
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
     *
     * @internal Exposed for the arch test that asserts every polymorphic
     *           `*_id` column on an exported table is either listed here or
     *           documented as intentionally un-remapped.
     */
    public const POLYMORPHIC_PAIRS = [
        'journal_entries' => [
            ['type' => 'source_type', 'id' => 'source_id'],
        ],
        // Filed-return lines snapshot the journal entry's source pair for the
        // drill-down; the FQCN is the same one journal_entries carries.
        'tax_return_lines' => [
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
     * Row ids held inside JSON columns: table => list of {column, path, table}
     * (see {@see JsonIdPaths} for the path syntax), optionally only on rows
     * whose columns match `when`.
     *
     * The orchestrator remaps these once every table is restored
     * ({@see self::remapJsonIds()}), since several point at tables restored
     * later (`consumed_layers` → `stock_layers`). Until then the row carries
     * the bundle's value: a JSON column has no constraint to trip.
     *
     * `document_folders.viewer_member_ids` is remapped by its own quirk below.
     * Every other JSON column on a backed-up table is in
     * {@see self::JSON_WITHOUT_IDS}; the arch test fails on one in neither.
     *
     * @var array<string, list<array{column: string, path: string, table: string, when?: array<string, string>}>>
     */
    public const JSON_ID_PATHS = [
        'companies' => [
            // Remembered reconciliation accounts, keyed by bank account id.
            ['column' => 'settings', 'path' => 'reconciliation.defaults.*.service_charge_account_id', 'table' => 'accounts'],
            ['column' => 'settings', 'path' => 'reconciliation.defaults.*.interest_account_id', 'table' => 'accounts'],
            ['column' => 'settings', 'path' => 'reconciliation.defaults.{key}', 'table' => 'accounts'],
        ],
        'stock_movements' => [
            // The FIFO layers an issue consumed; a reversal puts the quantity back on them.
            ['column' => 'consumed_layers', 'path' => '*.layer_id', 'table' => 'stock_layers'],
        ],
        'tax_returns' => [
            ['column' => 'excluded_journal_line_ids', 'path' => '*', 'table' => 'journal_lines'],
        ],
        'bank_reconciliations' => [
            ['column' => 'marked_line_ids', 'path' => '*', 'table' => 'journal_lines'],
        ],
        'bank_statement_lines' => [
            ['column' => 'suggested_bill_allocations', 'path' => '*.bill_id', 'table' => 'bills'],
        ],
        'payroll_slip_filing_lines' => [
            // The slip's own copy of the employee, alongside the contact_id column.
            ['column' => 'data', 'path' => 'contact_id', 'table' => 'contacts'],
        ],
        'memorized_reports' => self::REPORT_SETTINGS_IDS,
        'report_package_items' => self::REPORT_SETTINGS_IDS,
        'user_preferences' => [
            // The bank account a screen last showed (App\Support\Banking\LastBankAccount).
            ['column' => 'value', 'path' => '', 'table' => 'accounts', 'when' => ['pref_key' => 'banking.last_account']],
        ],
    ];

    /**
     * The id filters a saved report keeps (App\Support\Reporting\ReportSettings).
     *
     * @var list<array{column: string, path: string, table: string}>
     */
    private const REPORT_SETTINGS_IDS = [
        ['column' => 'settings', 'path' => 'accountId', 'table' => 'accounts'],
        ['column' => 'settings', 'path' => 'contactId', 'table' => 'contacts'],
        ['column' => 'settings', 'path' => 'classId', 'table' => 'classifications'],
        ['column' => 'settings', 'path' => 'locationId', 'table' => 'locations'],
        ['column' => 'settings', 'path' => 'fundId', 'table' => 'funds'],
    ];

    /**
     * JSON columns on backed-up tables that hold no row ids to remap, so they
     * restore exactly as the bundle has them. Two kinds:
     *
     *  - `none`: no ids at all (labels, amounts, codes, emails, settings).
     *  - `snapshot`: ids frozen as historical record, never acted on again. The
     *    audit payload feeds the rebuilt hash chain; an MCP proposal's payload
     *    is history once the restore has expired every pending one.
     *
     * @var array<string, array<string, 'none'|'snapshot'>>
     */
    public const JSON_WITHOUT_IDS = [
        'company_api_keys' => ['abilities' => 'none'],
        'company_members' => ['sections' => 'none'],
        'company_invitations' => ['sections' => 'none'],
        'accounting_audit_logs' => ['payload' => 'snapshot'],
        'deleted_transactions' => ['snapshot' => 'snapshot'], // what was deleted, as it stood
        'mcp_write_proposals' => ['payload' => 'snapshot'],
        'currency_revaluations' => ['rate_snapshot' => 'none'], // keyed by currency code
        'invoice_settings' => ['statement_columns' => 'none'],
        'tax_returns' => ['reconciliation' => 'none', 'vat_boxes' => 'none'],
        'payroll_remittances' => ['breakdown' => 'none'],
        'payroll_slip_filings' => ['summary' => 'none'],
        'deadlines' => ['annual_dates' => 'none', 'notify_days_before' => 'none'],
        'report_email_schedules' => ['recipients' => 'none'], // email addresses
        'grid_preferences' => ['visible_columns' => 'none'],
        'daily_insights' => ['facts' => 'none'],
        'inbox_items' => ['extracted' => 'none'], // OCR output
        'bank_import_profiles' => ['mapping' => 'none'],
        'bank_statement_imports' => ['mapping' => 'none', 'parse_meta' => 'none'],
        'bank_statement_lines' => ['raw' => 'none'],
    ];

    /**
     * What a restore does with a reference column that can't be null when the
     * row it names isn't in the bundle. Unlisted, such a reference fails the
     * restore.
     *
     *  - `zero`: keep the row, pointing at nothing. Audit rows routinely name
     *    records deleted since (a reposted document's old lines, a merged
     *    contact), and the trail must keep them.
     *  - `skip`: leave the row out. An attachment whose record is gone has
     *    nothing left to attach to.
     *
     * @var array<string, array<string, 'zero'|'skip'>>
     */
    public const DANGLING_REFERENCE_POLICY = [
        'accounting_audit_logs' => ['auditable_id' => 'zero'],
        'attachments' => ['attachable_id' => 'skip'],
    ];

    /**
     * `inbox_items.promoted_document_type` holds the promotion kind
     * ({@see PromoteInboxItem}), not a class name, so the generic polymorphic
     * pass can't resolve it. Kind => table of the document it became.
     *
     * @var array<string, string>
     */
    public const INBOX_PROMOTED_DOCUMENT_TABLES = [
        'bill' => 'bills',
        'reimbursement' => 'bills',
        'expense' => 'expenses',
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
    ];

    /**
     * Exact column names treated as `users.id` references. Mirrors
     * {@see ReferencedUsersExporter::USER_ID_EXACT} so the
     * export-side discovery and import-side remap stay in lockstep.
     *
     * @var list<string>
     *
     * @internal Exposed for the arch test that checks every `users` FK on an
     *           exported table is one the generic user remap will catch.
     */
    public const USER_ID_EXACT = ['created_by', 'uploaded_by_id', 'invited_by', 'user_id'];

    /**
     * Columns UNIQUE across the whole instance on the tables
     * {@see BackupTableRegistry::tables()} exports, and what a restore does
     * with each. Copied verbatim, any one of them fails a restore next to the
     * organization it came from (a copy on the same instance) on the
     * constraint, as company_api_keys.token_hash did in #158:
     *
     *  - `fresh`: the copy gets a new working value.
     *  - `drop`: the copy gets null.
     *  - `deactivate`: the row is kept, its value is replaced by one nobody
     *    holds, and the row comes back inactive (revoked, expired).
     *
     * A value is never kept just because it happens to be free on this
     * instance: that would make a restore's outcome depend on, and reveal,
     * what other organizations hold. An index that includes `company_id`, or a
     * parent FK remapped onto the copy's own rows, is already unique per copy
     * and is not listed.
     *
     * @var array<string, array<string, 'fresh'|'drop'|'deactivate'>>
     *
     * @internal Exposed for the arch test that fails when an exported table
     *           gains a globally unique column this map does not list.
     */
    public const GLOBALLY_UNIQUE_COLUMNS = [
        'companies' => [
            // The copy's own URL: CompanyImporter mints one from the name.
            'slug' => 'fresh',
            // Stripe Connect never crosses instances; the owner reconnects the copy.
            'stripe_account_id' => 'drop',
            // The original's forwarding address; the copy comes back with inbox email off.
            'inbound_email_token' => 'drop',
        ],
        'company_api_keys' => [
            // The secret never leaves the instance; the key comes back revoked.
            'token_hash' => 'deactivate',
        ],
        'company_invitations' => [
            // The emailed link opens the original's invitation; an open one comes back expired.
            'code' => 'deactivate',
        ],
        'customer_receipts' => [
            // Stripe's id for a payment the original already recorded; the copy has no Stripe account.
            'stripe_payment_intent_id' => 'drop',
        ],
        'mcp_write_proposals' => [
            // The agent holds the original's confirm token; a pending proposal comes back expired.
            'idempotency_key' => 'deactivate',
        ],
    ];

    /**
     * Tables of one person's own settings in an organization, unique per
     * (organization, user[, key]). A row whose user matched nobody here would
     * land on the importing user, so it is left out instead: someone else's
     * preferences aren't the importer's, and two such users would collide on
     * the unique key and fail the restore.
     *
     * @var list<string>
     */
    public const PERSONAL_TABLES = [
        'nav_preferences',
        'grid_preferences',
        'user_preferences',
        'report_favorites',
        'deadline_notification_settings',
    ];

    /**
     * Bundle user ids that matched nobody here (set: id => true).
     *
     * @var array<int, true>
     */
    private readonly array $fallbackUsers;

    /** The row's `user_id` as the bundle had it, before the user remap. */
    private ?int $sourceUserId = null;

    /**
     * @param  array<int, int>  $userIdMap  oldUserId => newUserId, including fallback entries.
     * @param  list<int>  $fallbackUserIds  Bundle user ids no user here matched by email.
     */
    public function __construct(
        private readonly IdMapper $idMapper,
        private readonly array $userIdMap,
        private readonly int $importingUserId,
        private readonly int $newCompanyId,
        array $fallbackUserIds = [],
    ) {
        $this->fallbackUsers = array_fill_keys($fallbackUserIds, true);
    }

    /**
     * Where each registry table restores, by name. Built once.
     *
     * @var array<string, int>|null
     */
    private static ?array $registryPositions = null;

    /**
     * Model class => its table, as {@see self::morphTable()} resolved it.
     *
     * @var array<string, string|null>
     */
    private static array $morphTables = [];

    /**
     * References deferred or orphaned by the row being transformed.
     *
     * @var array<string, array{table: string, id: int}>
     */
    private array $deferred = [];

    /** @var array<string, array{table: string, id: int}> */
    private array $orphans = [];

    /**
     * Transform a single row read from `data/{table}.jsonl` into the payload
     * the orchestrator will insert.
     *
     * `deferred` lists the references to patch in once every table is restored
     * (column => the table and old id it names); the row carries null for each
     * until then. `orphans` lists references to rows that aren't in the bundle,
     * restored as null.
     *
     * @param  array<string, mixed>  $row
     * @return array{row: array<string, mixed>, old_id: ?int, skip: bool, deferred: array<string, array{table: string, id: int}>, orphans: array<string, array{table: string, id: int}>}
     */
    public function transform(string $table, array $row): array
    {
        $this->deferred = [];
        $this->orphans = [];

        // 1. Capture and drop the row's `id` — the target DB will auto-increment.
        $oldId = $this->extractOldId($row);

        // 2. Swap `company_id`.
        if (array_key_exists('company_id', $row)) {
            $row['company_id'] = $this->newCompanyId;
        }

        // 3. User id remap.
        $userId = $row['user_id'] ?? null;
        $this->sourceUserId = is_int($userId) || (is_string($userId) && ctype_digit($userId)) ? (int) $userId : null;
        $row = $this->remapUserColumns($row);

        // 4. Parent FK remap.
        $row = $this->remapParentForeignKeys($table, $row);

        // 5. Polymorphic pair remap.
        $row = $this->remapPolymorphicPairs($table, $row);

        // 6. Strip cross-cycle FKs for later post-pass UPDATE.
        $this->extractDeferredForeignKeys($table, $row);

        // 7. Per-table quirks (and `skip` decisions).
        $result = $this->applyTableQuirks($table, $row, $oldId);
        $result['deferred'] = $this->deferred;
        $result['orphans'] = $this->orphans;

        return $result;
    }

    /**
     * The row's JSON columns with every id in {@see self::JSON_ID_PATHS} passed
     * through the id map; ids that don't map are dropped. Only the columns this
     * changes are returned, so the caller can UPDATE just those.
     *
     * Call it once IdMapper holds every table, with the row as the bundle has
     * it (arrays decoded).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function remapJsonIds(string $table, array $row): array
    {
        $changed = [];

        foreach (self::JSON_ID_PATHS[$table] ?? [] as $spec) {
            if (! self::jsonSpecApplies($spec, $row)) {
                continue;
            }

            $column = $spec['column'];
            $current = array_key_exists($column, $changed) ? $changed[$column] : ($row[$column] ?? null);

            if ($current === null) {
                continue;
            }

            $changed[$column] = JsonIdPaths::remap(
                $current,
                $spec['path'],
                fn (int $oldId): ?int => $this->idMapper->get($spec['table'], $oldId),
            );
        }

        return array_filter(
            $changed,
            static fn (mixed $value, string $column): bool => $value !== ($row[$column] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Whether a {@see self::JSON_ID_PATHS} entry covers this row.
     *
     * @param  array{column: string, path: string, table: string, when?: array<string, string>}  $spec
     * @param  array<string, mixed>  $row
     */
    public static function jsonSpecApplies(array $spec, array $row): bool
    {
        foreach ($spec['when'] ?? [] as $column => $expected) {
            if (($row[$column] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where `$table` restores in {@see BackupTableRegistry::tables()}; a table
     * outside the registry sorts after all of them.
     */
    public static function registryPosition(string $table): int
    {
        self::$registryPositions ??= array_flip(array_column(BackupTableRegistry::tables(), 'table'));

        return self::$registryPositions[$table] ?? PHP_INT_MAX;
    }

    /**
     * The table a reference column on `$table` points at, from
     * {@see self::PARENT_FK_MAP} or {@see self::DEFERRED_FK_COLUMNS}.
     */
    public static function referenceTarget(string $table, string $column): ?string
    {
        return self::PARENT_FK_MAP[$table][$column]
            ?? self::DEFERRED_FK_COLUMNS[$table][$column]
            ?? null;
    }

    /**
     * Resolve one reference to its id on this instance. Mapped: the new id.
     * Otherwise the reference is recorded as deferred or orphaned (see the
     * class docblock) and null is returned for the row to carry meanwhile.
     */
    private function resolveReference(string $table, string $column, string $targetTable, int $oldId): ?int
    {
        $newId = $this->idMapper->get($targetTable, $oldId);

        if ($newId !== null) {
            return $newId;
        }

        $targetPosition = self::registryPosition($targetTable);

        if ($targetPosition !== PHP_INT_MAX && $targetPosition >= self::registryPosition($table)) {
            $this->deferred[$column] = ['table' => $targetTable, 'id' => $oldId];
        } else {
            $this->orphans[$column] = ['table' => $targetTable, 'id' => $oldId];
        }

        return null;
    }

    /**
     * Strip the DEFERRED_FK_COLUMNS values still set on this row (the generic
     * pass has already deferred any it saw unmapped) and record them for the
     * orchestrator's post-loop UPDATE.
     *
     * @param  array<string, mixed>  $row  Mutated: each captured column is nulled.
     */
    private function extractDeferredForeignKeys(string $table, array &$row): void
    {
        foreach (self::DEFERRED_FK_COLUMNS[$table] ?? [] as $column => $parentTable) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];
            $row[$column] = null;

            if ((is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value))) {
                $this->deferred[$column] = ['table' => $parentTable, 'id' => (int) $value];
            }
        }
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

    public static function isUserColumn(string $column): bool
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

            $row[$column] = $this->resolveReference($table, $column, $targetTable, (int) $value);
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

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $targetTable = is_string($type) ? self::morphTable($type) : null;

            if ($targetTable === null) {
                // A class this instance doesn't have (a renamed model, a newer
                // version's feature): the row it names can't be found here.
                $this->orphans[$idCol] = ['table' => (string) $type, 'id' => (int) $id];
                $row[$idCol] = null;

                continue;
            }

            $row[$idCol] = $this->resolveReference($table, $idCol, $targetTable, (int) $id);
        }

        return $row;
    }

    /**
     * Resolve an Eloquent model FQCN to its table name without booting a real
     * instance against the DB. Returns null if the class can't be instantiated
     * cleanly (defensive — never lets an unknown class crash the import).
     */
    public static function morphTable(string $fqcn): ?string
    {
        if (array_key_exists($fqcn, self::$morphTables)) {
            return self::$morphTables[$fqcn];
        }

        if (! class_exists($fqcn)) {
            return self::$morphTables[$fqcn] = null;
        }

        try {
            $instance = new $fqcn;
        } catch (Throwable) {
            return self::$morphTables[$fqcn] = null;
        }

        if (! method_exists($instance, 'getTable')) {
            return self::$morphTables[$fqcn] = null;
        }

        $table = $instance->getTable();

        return self::$morphTables[$fqcn] = is_string($table) && $table !== '' ? $table : null;
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

        if (in_array($table, self::PERSONAL_TABLES, true) && $this->sourceUserId !== null
            && (isset($this->fallbackUsers[$this->sourceUserId]) || ! isset($this->userIdMap[$this->sourceUserId]))) {
            $skip = true;
        }

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

                // Inbound email finds the organization by this token, and the
                // copy must not receive the original's mail. Restore it as never
                // enabled: turning inbox email on mints a new address.
                $row['inbound_email_token'] = null;
                $row['inbound_email_enabled'] = false;
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
                // The secret never crosses the wire (the exporter drops
                // token_hash), but the row is kept: audit logs and MCP
                // proposals point at it. Give it a hash of a value nobody
                // holds, so it can never authenticate, and restore it revoked
                // so the API keys page says so. A key revoked before the
                // backup keeps its original date.
                $row['token_hash'] = hash('sha256', Str::random(64));
                $row['revoked_at'] ??= now();

                // Null any plaintext token columns defensively in case a
                // future schema gains one.
                foreach (['token', 'plaintext_token'] as $secretColumn) {
                    if (array_key_exists($secretColumn, $row)) {
                        $row[$secretColumn] = null;
                    }
                }
                break;

            case 'company_invitations':
                // The emailed link carries the original's code, so the copy's
                // invitation gets one nobody holds. One still open comes back
                // expired: it can never be accepted, and an expired invitation
                // doesn't stop the owner inviting that person again. Accepted
                // and lapsed invitations keep their dates.
                $row['code'] = Str::random(64);

                if (($row['accepted_at'] ?? null) === null && ! $this->isPast($row['expires_at'] ?? null)) {
                    $row['expires_at'] = now();
                }
                break;

            case 'customer_receipts':
                // Stripe webhooks find the organization by its connected
                // account, which the copy doesn't have, so it could never act
                // on the PaymentIntent id. The receipt's reference still
                // names the payment.
                $row['stripe_payment_intent_id'] = null;
                break;

            case 'mcp_write_proposals':
                // The agent holds the original's confirm token, so the copy's
                // proposal gets one nobody holds. One still pending comes back
                // expired, since nothing can confirm it; confirmed, rejected
                // and expired proposals keep their history.
                $row['idempotency_key'] = (string) Str::ulid();

                if (($row['status'] ?? McpProposalStatus::Pending->value) === McpProposalStatus::Pending->value) {
                    $row['status'] = McpProposalStatus::Expired->value;
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

            case 'inbox_items':
                // The promoted document's kind isn't a class name, so the
                // generic polymorphic pass skipped it. Bills and expenses
                // restore before inbox items, so the id maps directly.
                $kind = $row['promoted_document_type'] ?? null;
                $documentId = $row['promoted_document_id'] ?? null;
                $documentTable = is_string($kind) ? (self::INBOX_PROMOTED_DOCUMENT_TABLES[$kind] ?? null) : null;

                if ($documentTable !== null && (is_int($documentId) || (is_string($documentId) && ctype_digit($documentId)))) {
                    $row['promoted_document_id'] = $this->resolveReference($table, 'promoted_document_id', $documentTable, (int) $documentId);
                }
                break;

            case 'company_member_roles':
                // Follow the membership through IdMapper. A membership that was
                // skipped (its user fell onto the importing user, who becomes
                // the Owner and holds no role) maps to nothing: drop the row.
                $oldMemberId = $row['company_member_id'] ?? null;
                $newMemberId = (is_int($oldMemberId) || (is_string($oldMemberId) && ctype_digit($oldMemberId)))
                    ? $this->idMapper->get('company_members', (int) $oldMemberId)
                    : null;

                if ($newMemberId === null) {
                    $skip = true;
                } else {
                    $row['company_member_id'] = $newMemberId;
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

    /**
     * Whether a bundle timestamp (the exporter's ISO-8601 string) has passed.
     * Null or unreadable counts as not passed.
     */
    private function isPast(mixed $timestamp): bool
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($timestamp)->isPast();
        } catch (Throwable) {
            return false;
        }
    }
}
