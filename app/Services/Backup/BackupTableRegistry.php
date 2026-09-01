<?php

namespace App\Services\Backup;

use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationEntry;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\BankRule;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\BillPaymentApplication;
use App\Models\CcaPool;
use App\Models\Cheque;
use App\Models\ChequeLine;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyCurrency;
use App\Models\CompanyInvitation;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CreditMemoLine;
use App\Models\CurrencyRevaluation;
use App\Models\CustomerReceipt;
use App\Models\DailyInsight;
use App\Models\Deposit;
use App\Models\DepositLine;
use App\Models\DocumentFolder;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeRecurringItem;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\FormStyle;
use App\Models\Fund;
use App\Models\Grant;
use App\Models\GrantRecognition;
use App\Models\GridPreference;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePaymentRequest;
use App\Models\InvoiceReminderLog;
use App\Models\InvoiceSetting;
use App\Models\InvoiceTemplate;
use App\Models\InvoiceTemplateLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemComponent;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\JournalEntryTemplateLine;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\McpWriteProposal;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipLevel;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\NavPreference;
use App\Models\OpeningBalanceRow;
use App\Models\OpeningBalanceState;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\PayrollSlipFiling;
use App\Models\PayrollSlipFilingLine;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Models\PayRunLineAccrual;
use App\Models\PayRunLineContribution;
use App\Models\PayRunLineDeduction;
use App\Models\PayRunLineEarning;
use App\Models\PayRunLineManualEarning;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReceiptApplication;
use App\Models\RecurringDocument;
use App\Models\RecurringDocumentLine;
use App\Models\RecurringJournalEntry;
use App\Models\RecurringJournalEntryLine;
use App\Models\ReminderTier;
use App\Models\ReportEmailSchedule;
use App\Models\ReportFavorite;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\ReportSection;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReceipt;
use App\Models\SalesReceiptLine;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\TaxReturnLine;
use App\Models\TaxReturnPayment;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Models\Transfer;
use App\Models\VendorCredit;
use App\Models\VendorCreditLine;
use App\Models\WorkersCompSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * Authoritative, dependency-ordered list of per-company tables to include in
 * a backup bundle. Phase 1 exporter and Phase 2 importer both read from here.
 *
 * Ordering rule: parents before children. Phase 2 will replay this list
 * top-down so foreign-key references resolve as rows are inserted.
 *
 * To add a table:
 *   1. Append the entry in the correct group below.
 *   2. The arch test will fail with a regression guard if you forget.
 */
final class BackupTableRegistry
{
    /**
     * Ordered list of tables to export, with their Eloquent model FQCN and a
     * domain group label (for manifest + UI grouping).
     *
     * @return list<array{table: string, model: class-string<Model>, group: string}>
     */
    public static function tables(): array
    {
        return [
            // === Tenant root ===
            ['table' => 'companies', 'model' => Company::class, 'group' => 'core'],

            // === Core dimensions ===
            // No-FK dims first, then accounts (FK report_sections), then everything
            // that FKs accounts (company_currencies.*_account_id, tax_agencies.payable_account_id,
            // asset_categories.*, contacts.default_*_account_id, items.*_account_id, etc).
            ['table' => 'report_sections', 'model' => ReportSection::class, 'group' => 'core'],
            ['table' => 'classifications', 'model' => Classification::class, 'group' => 'core'],
            ['table' => 'locations', 'model' => Location::class, 'group' => 'core'],
            ['table' => 'funds', 'model' => Fund::class, 'group' => 'core'],
            ['table' => 'payment_terms', 'model' => PaymentTerm::class, 'group' => 'core'],
            ['table' => 'payment_methods', 'model' => PaymentMethod::class, 'group' => 'core'],
            ['table' => 'accounts', 'model' => Account::class, 'group' => 'core'],
            ['table' => 'company_currencies', 'model' => CompanyCurrency::class, 'group' => 'core'],
            ['table' => 'exchange_rates', 'model' => ExchangeRate::class, 'group' => 'core'],
            ['table' => 'tax_agencies', 'model' => TaxAgency::class, 'group' => 'core'],
            ['table' => 'tax_codes', 'model' => TaxCode::class, 'group' => 'core'],
            ['table' => 'asset_categories', 'model' => AssetCategory::class, 'group' => 'core'],
            ['table' => 'contacts', 'model' => Contact::class, 'group' => 'core'],
            // item_categories before items (items.item_category_id FKs it).
            ['table' => 'item_categories', 'model' => ItemCategory::class, 'group' => 'core'],
            ['table' => 'items', 'model' => Item::class, 'group' => 'core'],
            // item_components after items (both FKs target items).
            ['table' => 'item_components', 'model' => ItemComponent::class, 'group' => 'core'],
            // membership_levels FK accounts/payment_terms/tax_codes (all above).
            ['table' => 'membership_levels', 'model' => MembershipLevel::class, 'group' => 'core'],
            // members FK contacts + membership_levels (above); recurring_document_id
            // is a deferred FK (recurring_documents export later). Exported before
            // invoices so invoices.member_id resolves.
            ['table' => 'members', 'model' => Member::class, 'group' => 'core'],

            // === GL ===
            // journal_entries before journal_lines (line FK). audit logs and
            // revaluations both reference journal_entry_id so come after.
            ['table' => 'journal_entries', 'model' => JournalEntry::class, 'group' => 'gl'],
            ['table' => 'journal_lines', 'model' => JournalLine::class, 'group' => 'gl'],
            // Journal entry templates only reference catalog FKs (accounts/tax_codes/
            // classifications/locations/funds), so order within the GL group is free.
            ['table' => 'journal_entry_templates', 'model' => JournalEntryTemplate::class, 'group' => 'gl'],
            ['table' => 'journal_entry_template_lines', 'model' => JournalEntryTemplateLine::class, 'group' => 'gl'],
            ['table' => 'accounting_audit_logs', 'model' => AccountingAuditLog::class, 'group' => 'gl'],
            ['table' => 'currency_revaluations', 'model' => CurrencyRevaluation::class, 'group' => 'gl'],
            // Opening Balances workspace: states FK journal_entries (above) and
            // rows FK states + accounts, so states restore before rows.
            ['table' => 'opening_balance_states', 'model' => OpeningBalanceState::class, 'group' => 'gl'],
            ['table' => 'opening_balance_rows', 'model' => OpeningBalanceRow::class, 'group' => 'gl'],

            // === Sales ===
            ['table' => 'invoice_settings', 'model' => InvoiceSetting::class, 'group' => 'sales'],
            // form_styles before invoices (invoices.form_style_id FK).
            ['table' => 'form_styles', 'model' => FormStyle::class, 'group' => 'sales'],
            // Estimates can convert to sales_orders/invoices (FKs converted_*_id) — export first.
            ['table' => 'estimates', 'model' => Estimate::class, 'group' => 'sales'],
            ['table' => 'estimate_lines', 'model' => EstimateLine::class, 'group' => 'sales'],
            ['table' => 'sales_orders', 'model' => SalesOrder::class, 'group' => 'sales'],
            ['table' => 'sales_order_lines', 'model' => SalesOrderLine::class, 'group' => 'sales'],
            // invoices.sales_order_id -> sales_orders, invoice_lines.sales_order_line_id -> sales_order_lines.
            ['table' => 'invoices', 'model' => Invoice::class, 'group' => 'sales'],
            ['table' => 'invoice_lines', 'model' => InvoiceLine::class, 'group' => 'sales'],
            // Reminder tiers depend only on the company; the send log references
            // both invoices (above) and reminder_tiers, so it must follow them.
            ['table' => 'reminder_tiers', 'model' => ReminderTier::class, 'group' => 'sales'],
            ['table' => 'invoice_reminder_logs', 'model' => InvoiceReminderLog::class, 'group' => 'sales'],
            ['table' => 'invoice_payment_requests', 'model' => InvoicePaymentRequest::class, 'group' => 'sales'],
            // Invoice templates only reference catalog FKs (items/accounts/tax_codes/
            // classifications/locations, all in core above), so order among sales is free.
            ['table' => 'invoice_templates', 'model' => InvoiceTemplate::class, 'group' => 'sales'],
            ['table' => 'invoice_template_lines', 'model' => InvoiceTemplateLine::class, 'group' => 'sales'],
            ['table' => 'credit_memos', 'model' => CreditMemo::class, 'group' => 'sales'],
            ['table' => 'credit_memo_lines', 'model' => CreditMemoLine::class, 'group' => 'sales'],
            ['table' => 'customer_receipts', 'model' => CustomerReceipt::class, 'group' => 'sales'],
            // receipt_applications has no company_id (scoped via parent receipt) but
            // is included to round-trip the AR application graph.
            ['table' => 'receipt_applications', 'model' => ReceiptApplication::class, 'group' => 'sales'],
            // Pay-now sales receipts. Exported before deposits so a UF-parked
            // receipt that a deposit_line references resolves on restore.
            ['table' => 'sales_receipts', 'model' => SalesReceipt::class, 'group' => 'sales'],
            ['table' => 'sales_receipt_lines', 'model' => SalesReceiptLine::class, 'group' => 'sales'],
            ['table' => 'deposits', 'model' => Deposit::class, 'group' => 'sales'],
            ['table' => 'deposit_lines', 'model' => DepositLine::class, 'group' => 'sales'],
            ['table' => 'donation_receipts', 'model' => DonationReceipt::class, 'group' => 'sales'],
            // donations FK donation_receipts (above) via donation_receipt_id; the
            // reverse donation_receipts.donation_id is a deferred FK.
            ['table' => 'donations', 'model' => Donation::class, 'group' => 'sales'],
            // grants FK contacts/funds/accounts/journal_entries (all above);
            // grant_recognitions FK grants (header before child).
            ['table' => 'grants', 'model' => Grant::class, 'group' => 'sales'],
            ['table' => 'grant_recognitions', 'model' => GrantRecognition::class, 'group' => 'sales'],

            // === Purchases ===
            ['table' => 'purchase_orders', 'model' => PurchaseOrder::class, 'group' => 'purchases'],
            ['table' => 'purchase_order_lines', 'model' => PurchaseOrderLine::class, 'group' => 'purchases'],
            // bills.purchase_order_id -> purchase_orders, bill_lines.purchase_order_line_id -> purchase_order_lines.
            ['table' => 'bills', 'model' => Bill::class, 'group' => 'purchases'],
            ['table' => 'bill_lines', 'model' => BillLine::class, 'group' => 'purchases'],
            ['table' => 'vendor_credits', 'model' => VendorCredit::class, 'group' => 'purchases'],
            ['table' => 'vendor_credit_lines', 'model' => VendorCreditLine::class, 'group' => 'purchases'],
            ['table' => 'bill_payments', 'model' => BillPayment::class, 'group' => 'purchases'],
            // bill_payment_applications has no company_id (scoped via parent payment).
            ['table' => 'bill_payment_applications', 'model' => BillPaymentApplication::class, 'group' => 'purchases'],
            // cheques.credit_memo_id -> credit_memos so cheques come after credit memos
            // (already exported in Sales group above).
            ['table' => 'cheques', 'model' => Cheque::class, 'group' => 'purchases'],
            ['table' => 'cheque_lines', 'model' => ChequeLine::class, 'group' => 'purchases'],
            // expenses.payment_account_id -> accounts; no dependency on other purchase docs.
            ['table' => 'expenses', 'model' => Expense::class, 'group' => 'purchases'],
            ['table' => 'expense_lines', 'model' => ExpenseLine::class, 'group' => 'purchases'],
            ['table' => 'transfers', 'model' => Transfer::class, 'group' => 'purchases'],

            // === Inventory ===
            // stock_movements is the source of layers; layers reference movements.
            ['table' => 'stock_movements', 'model' => StockMovement::class, 'group' => 'inventory'],
            ['table' => 'stock_layers', 'model' => StockLayer::class, 'group' => 'inventory'],
            ['table' => 'stock_adjustments', 'model' => StockAdjustment::class, 'group' => 'inventory'],
            ['table' => 'stock_adjustment_lines', 'model' => StockAdjustmentLine::class, 'group' => 'inventory'],

            // === Tax / Reconciliation / Assets ===
            ['table' => 'tax_returns', 'model' => TaxReturn::class, 'group' => 'tax'],
            ['table' => 'tax_return_lines', 'model' => TaxReturnLine::class, 'group' => 'tax'],
            ['table' => 'tax_return_payments', 'model' => TaxReturnPayment::class, 'group' => 'tax'],
            // partners (T5013 allocation) carry only company_id — no parent FK, so
            // no PARENT_FK_MAP entry is needed; the generic company_id swap suffices.
            ['table' => 'partners', 'model' => Partner::class, 'group' => 'tax'],
            ['table' => 'bank_reconciliations', 'model' => BankReconciliation::class, 'group' => 'reconciliation'],
            // Bank rules are durable user config (categorization) — FKs accounts only.
            ['table' => 'bank_rules', 'model' => BankRule::class, 'group' => 'reconciliation'],
            ['table' => 'assets', 'model' => Asset::class, 'group' => 'assets'],
            // asset_depreciation_entries FK assets (above) and journal_entries
            // (GL group, earlier) — the per-asset-per-month idempotency ledger
            // behind generated book-depreciation drafts.
            ['table' => 'asset_depreciation_entries', 'model' => AssetDepreciationEntry::class, 'group' => 'assets'],
            // cca_pools store the T2125 opening UCC per CCA class; company_id only.
            ['table' => 'cca_pools', 'model' => CcaPool::class, 'group' => 'assets'],

            // === Payroll === (parents before children for FK-safe restore)
            ['table' => 'payroll_schedules', 'model' => PayrollSchedule::class, 'group' => 'payroll'],
            ['table' => 'employee_payroll_profiles', 'model' => EmployeePayrollProfile::class, 'group' => 'payroll'],
            ['table' => 'employee_recurring_items', 'model' => EmployeeRecurringItem::class, 'group' => 'payroll'],
            ['table' => 'employee_accrual_balances', 'model' => EmployeeAccrualBalance::class, 'group' => 'payroll'],
            ['table' => 'time_off_policies', 'model' => TimeOffPolicy::class, 'group' => 'payroll'],
            ['table' => 'employee_time_off_policies', 'model' => EmployeeTimeOffPolicy::class, 'group' => 'payroll'],
            ['table' => 'pay_runs', 'model' => PayRun::class, 'group' => 'payroll'],
            ['table' => 'pay_run_lines', 'model' => PayRunLine::class, 'group' => 'payroll'],
            ['table' => 'pay_run_line_earnings', 'model' => PayRunLineEarning::class, 'group' => 'payroll'],
            ['table' => 'pay_run_line_deductions', 'model' => PayRunLineDeduction::class, 'group' => 'payroll'],
            ['table' => 'pay_run_line_contributions', 'model' => PayRunLineContribution::class, 'group' => 'payroll'],
            ['table' => 'pay_run_line_manual_earnings', 'model' => PayRunLineManualEarning::class, 'group' => 'payroll'],
            ['table' => 'pay_run_line_accruals', 'model' => PayRunLineAccrual::class, 'group' => 'payroll'],
            ['table' => 'payroll_cheques', 'model' => PayrollCheque::class, 'group' => 'payroll'],
            ['table' => 'payroll_remittances', 'model' => PayrollRemittance::class, 'group' => 'payroll'],
            // Finalized year-end slip filings (T4/RL-1/T4A): the filing snapshot
            // (parent) before its per-recipient lines (child) for FK-safe restore.
            ['table' => 'payroll_slip_filings', 'model' => PayrollSlipFiling::class, 'group' => 'payroll'],
            ['table' => 'payroll_slip_filing_lines', 'model' => PayrollSlipFilingLine::class, 'group' => 'payroll'],
            ['table' => 'workers_comp_settings', 'model' => WorkersCompSetting::class, 'group' => 'payroll'],
            // time_off_requests before time_entries (entries FK their request).
            ['table' => 'time_off_requests', 'model' => TimeOffRequest::class, 'group' => 'payroll'],
            // time_entries references invoices (sales) AND pay_runs (payroll) — both are
            // exported in earlier groups, so this row's FKs resolve on restore.
            ['table' => 'time_entries', 'model' => TimeEntry::class, 'group' => 'payroll'],

            // === Recurring ===
            ['table' => 'recurring_documents', 'model' => RecurringDocument::class, 'group' => 'recurring'],
            ['table' => 'recurring_document_lines', 'model' => RecurringDocumentLine::class, 'group' => 'recurring'],
            ['table' => 'recurring_journal_entries', 'model' => RecurringJournalEntry::class, 'group' => 'recurring'],
            ['table' => 'recurring_journal_entry_lines', 'model' => RecurringJournalEntryLine::class, 'group' => 'recurring'],

            // === User-affordances (per-company UI state + auth) ===
            ['table' => 'memorized_report_groups', 'model' => MemorizedReportGroup::class, 'group' => 'user_affordances'],
            ['table' => 'memorized_reports', 'model' => MemorizedReport::class, 'group' => 'user_affordances'],
            // After both memorized tables — a schedule references either one.
            ['table' => 'report_email_schedules', 'model' => ReportEmailSchedule::class, 'group' => 'user_affordances'],
            // Package before items (parent), items after memorized_reports too —
            // an item may reference the memorized report it was sourced from.
            ['table' => 'report_packages', 'model' => ReportPackage::class, 'group' => 'user_affordances'],
            ['table' => 'report_package_items', 'model' => ReportPackageItem::class, 'group' => 'user_affordances'],
            ['table' => 'report_favorites', 'model' => ReportFavorite::class, 'group' => 'user_affordances'],
            // Per-user, per-company hidden sidebar items. Like report_favorites:
            // company_id + user_id only, both handled by the generic remap.
            ['table' => 'nav_preferences', 'model' => NavPreference::class, 'group' => 'user_affordances'],
            // Per-user, per-company grid column choices. Like nav_preferences:
            // company_id + user_id only, both handled by the generic remap.
            ['table' => 'grid_preferences', 'model' => GridPreference::class, 'group' => 'user_affordances'],
            // Daily "Did you know?" insights. Carry only company_id — like
            // partners, no PARENT_FK_MAP entry is needed; the generic
            // company_id swap suffices.
            ['table' => 'daily_insights', 'model' => DailyInsight::class, 'group' => 'user_affordances'],
            // Membership uses table `company_members`. Phase 2 will re-link to users via email-match.
            ['table' => 'company_members', 'model' => Membership::class, 'group' => 'user_affordances'],
            ['table' => 'company_invitations', 'model' => CompanyInvitation::class, 'group' => 'user_affordances'],
            // API keys: hashed token + last_four only — plaintext is unrecoverable.
            // Documented as such in manifest.exclusions hints.
            ['table' => 'company_api_keys', 'model' => CompanyApiKey::class, 'group' => 'user_affordances'],
            // Agentic-MCP propose→confirm proposals. FK company_api_keys (above)
            // and journal_entries (GL group, earlier), so it follows both.
            ['table' => 'mcp_write_proposals', 'model' => McpWriteProposal::class, 'group' => 'user_affordances'],

            // === Polymorphic (must come last — references rows in any of the above) ===
            // document_folders precedes attachments: a repository document is an
            // Attachment whose attachable is a folder, so the folder ids must be
            // registered in IdMapper before the attachments polymorphic remap runs.
            ['table' => 'document_folders', 'model' => DocumentFolder::class, 'group' => 'polymorphic'],
            ['table' => 'attachments', 'model' => Attachment::class, 'group' => 'polymorphic'],
            // Document inbox items. FK-reference attachments (attachment_id) and
            // contacts (suggested_contact_id), so they must follow attachments —
            // the latest table — even though they're a user-affordance concept.
            // promoted_document_id is a polymorphic (promoted_document_type)
            // pointer to bills/expenses, intentionally left un-remapped.
            ['table' => 'inbox_items', 'model' => InboxItem::class, 'group' => 'user_affordances'],
        ];
    }

    /**
     * Tables that physically have a `company_id` column but are intentionally
     * NOT exported. The arch test confirms that every `company_id`-bearing
     * table is either in tables() or this list.
     *
     * @return list<array{table: string, reason: string}>
     */
    public static function excludedTables(): array
    {
        return [
            // Instance-global / cross-tenant audit trail of authentication events.
            // Tied to users, not to a company's accounting state — useless on restore.
            ['table' => 'security_logs', 'reason' => 'security audit trail; cross-tenant; never restored'],

            // Single-shot QuickBooks/CSV migration run state. A backup is a
            // *snapshot* of post-migration state — replaying old import runs on
            // restore would conflict with already-imported data.
            ['table' => 'data_migration_runs', 'reason' => 'transient import job state; not part of accounting truth'],

            // Bank-statement import staging (same rationale as data_migration_runs):
            // a processing/audit artifact, not accounting truth. The journal entries,
            // reconciliation, and cleared flags it produces are already backed up via
            // the GL + bank_reconciliations, and the uploaded statement file rides
            // along as a reconciliation attachment.
            ['table' => 'bank_statement_imports', 'reason' => 'transient statement-import staging; accounting truth lives in the GL + reconciliation'],
            ['table' => 'bank_statement_lines', 'reason' => 'child of bank_statement_imports; excluded with parent'],

            // Saved CSV/Excel column mappings — durable convenience config, not
            // accounting truth. Promote to tables() (with FK remap) if backup parity
            // is ever wanted; a restored company simply re-saves a mapping on first use.
            ['table' => 'bank_import_profiles', 'reason' => 'reusable import mappings; convenience config, re-created on demand'],

            // Magic-link tokens for the customer payment portal — short-lived,
            // single-use, hashed. No value on restore.
            ['table' => 'portal_login_links', 'reason' => 'ephemeral signed magic-link tokens'],

            // How far audit:verify has walked each chain. Derived state, and
            // restoring it would be actively wrong: a restored company sits on a
            // different chain, so an inherited watermark describes rows that no
            // longer exist. Absent it, the next run re-verifies from genesis and
            // writes a fresh one — which is exactly the wanted behaviour.
            ['table' => 'audit_chain_checkpoints', 'reason' => 'derived verification watermark; regenerates on the next audit:verify run'],

            // Meta-table tracking backup jobs themselves. A backup of backup
            // history makes no sense; the restored company will create its own.
            ['table' => 'company_backups', 'reason' => 'meta-table tracking this very backup feature; self-referential'],

            // Meta-table tracking restore jobs themselves. A backup of restore
            // history makes no sense; the restored company will track its own.
            ['table' => 'company_restores', 'reason' => 'meta-table tracking the restore feature; self-referential'],

            // Budgets — not in Phase 1 scope. Add to tables() (and PARENT_FK_MAP,
            // DEFERRED_FK_COLUMNS as needed) if/when backup parity is required.
            ['table' => 'budgets', 'reason' => 'feature added after Phase 1; not yet in backup scope'],
            ['table' => 'budget_lines', 'reason' => 'child of budgets; excluded with parent'],

            // Cross-company reporting groups (instance-global concept).
            // Excluded per plan: per-company export must not leak other companies'
            // group memberships, and a restored fresh company has no peers anyway.
            ['table' => 'report_group_companies', 'reason' => 'cross-tenant group membership; instance-global feature'],
            ['table' => 'report_group_account_maps', 'reason' => 'cross-tenant group account map; instance-global feature'],

            // Platform-level support tickets. Belong to a *user* and the Site Admin
            // operator, not to a company's accounting state; `company_id` is only
            // triage context. Not exported per company. (support_ticket_messages has
            // no company_id, so the arch test doesn't require it here.)
            ['table' => 'support_tickets', 'reason' => 'platform-level support tickets; owned by a user, not accounting truth'],
        ];
    }
}
