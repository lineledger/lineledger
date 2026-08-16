<?php

namespace App\Support\Navigation;

use App\Enums\Country;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The directory of sidebar navigation groups and their links. A single source
 * of truth so the rendered sidebar ({@see resources/views/layouts/app/sidebar.blade.php})
 * and the per-user "Sidebar" settings page never drift.
 *
 * Visibility mirrors the gating that previously lived inline in the sidebar:
 * a group is included only when the user can access its {@see Section}, and
 * individual links are gated by company feature flags, registered-route checks,
 * and jurisdiction. The Dashboard (always-visible home) and the Reports
 * favorites list (its own Livewire island) are intentionally excluded.
 *
 * Each item carries a stable string key (e.g. "banking.import_statement") used
 * to persist a user's hide/show preference. These keys are persisted, so they
 * must remain stable. Group keys reuse the existing `data-sidebar-group` tokens
 * (e.g. "customers", "vendors") so saved expand/collapse cookies keep working.
 *
 * @phpstan-type NavItem array{key: string, label: string, icon: string, href: string, current: list<string>, section?: bool}
 * @phpstan-type NavGroup array{key: string, label: string, items: list<NavItem>}
 */
class SidebarNavCatalog
{
    /**
     * The ordered, gated navigation groups visible to the given user, with
     * every link's URL pre-resolved. Groups with no visible links are dropped.
     *
     * @return list<NavGroup>
     */
    public static function forUser(Company $company, User $user): array
    {
        $jurisdiction = $company->jurisdiction ?? Country::Canada;

        $featuresEmployees = (bool) ($company->features_employees ?? true);
        $featuresPayroll = $company->usesPayroll();
        $featuresInventory = (bool) ($company->features_inventory ?? true);
        $featuresFixedAssets = (bool) ($company->features_fixed_assets ?? true);
        $featuresEstimates = (bool) ($company->features_estimates ?? true);
        $featuresSalesOrders = (bool) ($company->features_sales_orders ?? true);
        $featuresPurchaseOrders = (bool) ($company->features_purchase_orders ?? true);
        $featuresRecurringInvoices = (bool) ($company->features_recurring_invoices ?? true);
        $featuresRecurringBills = (bool) ($company->features_recurring_bills ?? true);
        $featuresBudgets = (bool) ($company->features_budgets ?? true);
        $featuresFundraising = (bool) ($company->features_fundraising ?? false);
        $featuresMembership = (bool) ($company->features_membership ?? false);
        $isNonProfit = (bool) $company->organization_type?->isNonProfit();

        // A section shows only when the user may access it AND it is enabled
        // platform-wide (the site admin's global kill switch). Folding the
        // global check in here gates every group below in one place.
        // Company::sectionEnabled() layers in per-company admin overrides
        // (currently Payroll only) on top of the global kill switch.
        $can = fn (Section $section): bool => $company->sectionEnabled($section)
            && $user->canAccessSection($company, $section);

        $showFundraising = $featuresFundraising && $can(Section::Fundraising)
            && ($company->isRegisteredCharity()
                || Route::has('donations.index')
                || Route::has('grants.index'));

        $groups = [];

        if ($can(Section::Banking)) {
            $groups[] = self::group('banking', __('Banking'), [
                self::item('banking.bank_register', __('Bank register'), 'building-library', 'banking.register', ['banking.register']),
                self::item('banking.cheques', $jurisdiction->cheque('plural'), 'check', 'cheques.index', ['cheques.*']),
                self::item('banking.deposits', __('Deposits'), 'banknotes', 'deposits.index', ['deposits.*']),
                self::item('banking.transfers', __('Transfers'), 'arrows-right-left', 'transfers.index', ['transfers.*']),
            ]);
        }

        if ($showFundraising) {
            $items = [];
            if (Route::has('donations.index')) {
                $items[] = self::item('fundraising.donations', __('Donations'), 'gift', 'donations.index', ['donations.*']);
            }
            if (Route::has('grants.index')) {
                $items[] = self::item('fundraising.grants', __('Grants'), 'banknotes', 'grants.index', ['grants.*']);
            }
            if ($company->isRegisteredCharity()) {
                $items[] = self::item('fundraising.donation_receipts', __('Donation receipts'), 'document-check', 'donation-receipts.index', ['donation-receipts.*']);
            }
            $groups[] = self::group('fundraising', __('Fundraising'), $items);
        }

        if ($can(Section::Customers)) {
            $items = [self::item('sales.customers', __('Customers'), 'users', 'customers.index', ['customers.*'])];
            if ($featuresMembership) {
                $items[] = self::item('sales.members', __('Members'), 'identification', 'members.index', ['members.*']);
            }
            if ($featuresEstimates) {
                $items[] = self::item('sales.estimates', __('Estimates'), 'clipboard-document-list', 'estimates.index', ['estimates.*']);
            }
            if ($featuresSalesOrders) {
                $items[] = self::item('sales.sales_orders', __('Sales orders'), 'clipboard-document-check', 'sales-orders.index', ['sales-orders.*']);
            }
            $items[] = self::item('sales.invoices', __('Invoices'), 'document-text', 'invoices.index', ['invoices.*']);
            $items[] = self::item('sales.invoice_templates', __('Invoice templates'), 'document-duplicate', 'invoice-templates.index', ['invoice-templates.*']);
            $items[] = self::item('sales.sales_receipts', __('Sales receipts'), 'currency-dollar', 'sales-receipts.index', ['sales-receipts.*']);
            $items[] = self::item('sales.credit_memos', __('Credit memos'), 'receipt-refund', 'credit-memos.index', ['credit-memos.*']);
            if ($featuresRecurringInvoices) {
                $items[] = self::item('sales.recurring_invoices', __('Recurring'), 'arrow-path', 'recurring.index', ['recurring.*'], ['type' => 'invoice']);
            }
            $items[] = self::item('sales.receipts', __('Receipts'), 'banknotes', 'receipts.index', ['receipts.*']);
            // Non-profits frame incoming money as "Revenues" rather than "Sales".
            // The group key stays "customers" so saved expand/collapse state holds.
            $groups[] = self::group('customers', $isNonProfit ? __('Revenues') : __('Sales'), $items);
        }

        if ($can(Section::Vendors)) {
            $items = [self::item('purchases.vendors', __('Vendors'), 'building-storefront', 'vendors.index', ['vendors.*'])];
            if ($featuresPurchaseOrders) {
                $items[] = self::item('purchases.purchase_orders', __('Purchase orders'), 'clipboard-document-check', 'purchase-orders.index', ['purchase-orders.*']);
            }
            $items[] = self::item('purchases.bills', __('Bills'), 'document-text', 'bills.index', ['bills.*']);
            $items[] = self::item('purchases.vendor_credits', __('Vendor credits'), 'receipt-refund', 'vendor-credits.index', ['vendor-credits.*']);
            $items[] = self::item('purchases.bill_payments', __('Bill payments'), 'credit-card', 'bill-payments.index', ['bill-payments.*']);
            $items[] = self::item('purchases.expenses', __('Expenses'), 'banknotes', 'expenses.index', ['expenses.*']);
            if ($featuresRecurringBills) {
                $items[] = self::item('purchases.recurring_bills', __('Recurring'), 'arrow-path', 'recurring.index', ['recurring.*'], ['type' => 'bill']);
            }
            $groups[] = self::group('vendors', __('Purchases'), $items);
        }

        if ($featuresInventory && $can(Section::Inventory)) {
            $groups[] = self::group('inventory', __('Inventory'), [
                self::item('inventory.stock_on_hand', __('Stock on hand'), 'archive-box', 'inventory.index', ['inventory.index']),
                self::item('inventory.adjustments', __('Adjustments'), 'adjustments-horizontal', 'inventory.adjustments.index', ['inventory.adjustments.*']),
            ]);
        }

        if ($featuresEmployees && $can(Section::Employees)) {
            $groups[] = self::group('employees', __('Employees'), [
                self::item('employees.employees', __('Employees'), 'identification', 'employees.index', ['employees.*']),
                self::item('employees.reimbursements', __('Reimbursements'), 'receipt-percent', 'reimbursements.index', ['reimbursements.*']),
            ]);
        }

        if ($featuresPayroll && $can(Section::Payroll)) {
            $items = [
                self::item('payroll.overview', __('Overview'), 'banknotes', 'payroll.index', ['payroll.index']),
                self::item('payroll.employee_setup', __('Employee setup'), 'identification', 'payroll.employees.index', ['payroll.employees.*']),
                self::item('payroll.staff_calendar', __('Staff calendar'), 'calendar-days', 'payroll.staff-calendar', ['payroll.staff-calendar']),
                self::item('payroll.pay_runs', __('Pay runs'), 'calendar-days', 'pay-runs.index', ['pay-runs.*']),
                self::section('payroll.reports_header', __('Reports')),
                self::item('payroll.remittance_history', __('Remittance history'), 'clipboard-document-check', 'payroll.reports.remittances', ['payroll.reports.remittances']),
                self::item('payroll.pd7a_remittance', __('PD7A'), 'document-chart-bar', 'payroll.reports.pd7a', ['payroll.reports.pd7a']),
                self::item('payroll.workers_comp', __("Workers' comp"), 'shield-check', 'payroll.reports.workers-comp', ['payroll.reports.workers-comp']),
                self::item('payroll.t4_slips', __('T4 slips'), 'document-text', 'payroll.reports.t4', ['payroll.reports.t4']),
                self::item('payroll.roe', __('Record of Employment'), 'document-arrow-down', 'payroll.reports.roe', ['payroll.reports.roe']),
                self::item('payroll.calculation_check', __('Calculation check'), 'check-badge', 'payroll.reports.verification', ['payroll.reports.verification']),
            ];
            $groups[] = self::group('payroll', __('Payroll'), $items);
        }

        if ($can(Section::Accounting)) {
            $items = [
                self::item('accounting.chart_of_accounts', __('Chart of Accounts'), 'book-open-text', 'accounts.index', ['accounts.*']),
                self::item('accounting.journal', __('Journal'), 'document-text', 'journal.index', ['journal.*']),
                self::item('accounting.journal_templates', __('Journal templates'), 'document-duplicate', 'journal-entry-templates.index', ['journal-entry-templates.*']),
                self::item('accounting.recurring_entries', __('Recurring entries'), 'arrow-path', 'recurring-journal.index', ['recurring-journal.*']),
            ];
            if ($featuresFixedAssets) {
                $items[] = self::item('accounting.fixed_assets', __('Fixed assets'), 'cube', 'assets.index', ['assets.*']);
            }
            $groups[] = self::group('accounting', __('Accounting'), $items);
        }

        if ($can(Section::Reports)) {
            $items = [self::item('reports.all_reports', __('All Reports'), 'chart-bar', 'reports.index', ['reports.*', 'tax-returns.*'])];
            if ($featuresBudgets) {
                $items[] = self::item('reports.budgets', __('Budgets'), 'calculator', 'budgets.index', ['budgets.*']);
            }
            $groups[] = self::group('reports', __('Reports'), $items);
        }

        if ($can(Section::Documents)) {
            $groups[] = self::group('documents', __('Documents'), [
                self::item('documents.repository', __('Repository'), 'folder', 'documents.index', ['documents.index', 'documents.show']),
                self::item('documents.attachment_index', __('Attachment index'), 'paper-clip', 'documents.attached-index', ['documents.attached-index']),
            ]);
        }

        if ($can(Section::Inbox)) {
            $groups[] = self::group('inbox', __('Inbox'), [
                self::item('inbox.review_queue', __('Review queue'), 'inbox-arrow-down', 'inbox.index', ['inbox.*']),
            ]);
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * A flat key => label map of every group and item key available to the
     * user. Used to validate and bound the keys a user may persist.
     *
     * @return array<string, string>
     */
    public static function flattenKeys(Company $company, User $user): array
    {
        $keys = [];

        foreach (self::forUser($company, $user) as $group) {
            $keys[$group['key']] = $group['label'];

            foreach ($group['items'] as $item) {
                if (! ($item['section'] ?? false)) {
                    $keys[$item['key']] = $item['label'];
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<NavItem>  $items
     * @return NavGroup
     */
    protected static function group(string $key, string $label, array $items): array
    {
        return ['key' => $key, 'label' => $label, 'items' => $items];
    }

    /**
     * @param  list<string>  $current  Route patterns matched for the active state.
     * @param  array<string, mixed>  $params  Route parameters for the href.
     * @return NavItem
     */
    protected static function item(string $key, string $label, string $icon, string $route, array $current, array $params = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'href' => route($route, $params),
            'current' => $current,
        ];
    }

    /**
     * A non-link section divider rendered as a label within a nav group.
     * Section items are excluded from user-hideable keys.
     *
     * @return NavItem
     */
    protected static function section(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => '',
            'href' => '',
            'current' => [],
            'section' => true,
        ];
    }
}
