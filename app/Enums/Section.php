<?php

namespace App\Enums;

/**
 * An access unit in the application, mirroring the sidebar groups plus Settings.
 *
 * A company member is granted access to a set of sections. Routes are mapped to
 * the section(s) they belong to via {@see self::forRouteName()}, which the
 * EnsureSectionAccess middleware uses to authorize each request.
 */
enum Section: string
{
    case Customers = 'customers';
    case Vendors = 'vendors';
    case Employees = 'employees';
    case Payroll = 'payroll';
    case Inventory = 'inventory';
    case Accounting = 'accounting';
    case Banking = 'banking';
    case Inbox = 'inbox';
    case Fundraising = 'fundraising';
    case Reports = 'reports';
    case Lists = 'lists';
    case Documents = 'documents';
    case Settings = 'settings';

    /**
     * The display label for the section.
     */
    public function label(): string
    {
        return match ($this) {
            self::Customers => __('Customers'),
            self::Vendors => __('Vendors'),
            self::Employees => __('Employees'),
            self::Payroll => __('Payroll'),
            self::Inventory => __('Inventory'),
            self::Accounting => __('Accounting'),
            self::Banking => __('Banking'),
            self::Inbox => __('Inbox'),
            self::Fundraising => __('Fundraising'),
            self::Reports => __('Reports'),
            self::Lists => __('Lists'),
            self::Documents => __('Documents'),
            self::Settings => __('Settings'),
        };
    }

    /**
     * A short description of what the section grants access to.
     */
    public function description(): string
    {
        return match ($this) {
            self::Customers => __('Customers, estimates, sales orders, invoices, credit memos and receipts.'),
            self::Vendors => __('Vendors, bills and bill payments.'),
            self::Employees => __('Employees and reimbursements.'),
            self::Payroll => __('Payroll setup, pay runs, payroll cheques and remittance reports.'),
            self::Inventory => __('Stock on hand and inventory adjustments.'),
            self::Accounting => __('Chart of accounts, journal and fixed assets.'),
            self::Banking => __('Bank register, reconciliation, cheques and deposits.'),
            self::Inbox => __('Incoming receipts and bills awaiting review.'),
            self::Fundraising => __('Donations, grants and official donation receipts.'),
            self::Reports => __('Financial reports and tax returns.'),
            self::Lists => __('Items, tax codes and payment terms.'),
            self::Documents => __('Company document repository and attachment index.'),
            self::Settings => __('Company settings and integrations.'),
        };
    }

    /**
     * All section values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $section) => $section->value, self::cases());
    }

    /**
     * Resolve a route name to the section(s) that gate it.
     *
     * Returns an empty array for ungated routes (e.g. the dashboard or
     * attachment downloads). Most routes map to a single section; recurring
     * documents are shared by sales and purchases, so access is granted when
     * the user can reach either Customers or Vendors.
     *
     * @return array<int, self>
     */
    public static function forRouteName(?string $name): array
    {
        if ($name === null || $name === '') {
            return [];
        }

        $segment = explode('.', $name)[0];

        return match ($segment) {
            'customers', 'estimates', 'sales-orders', 'invoices', 'invoice-templates', 'credit-memos', 'receipts', 'sales-receipts', 'members', 'reminders' => [self::Customers],
            'vendors', 'bills', 'bill-payments', 'purchase-orders', 'vendor-credits', 'expenses' => [self::Vendors],
            'donations', 'donation-receipts', 'grants' => [self::Fundraising],
            'recurring' => [self::Customers, self::Vendors],
            'employees', 'reimbursements' => [self::Employees],
            'payroll', 'pay-runs', 'payroll-schedules', 'payroll-cheques', 'time-entries', 'time-off-policies', 'time-off-requests' => [self::Payroll],
            'inventory' => [self::Inventory],
            'accounts', 'journal', 'journal-entry-templates', 'recurring-journal', 'assets' => [self::Accounting],
            'banking', 'cheques', 'deposits', 'transfers' => [self::Banking],
            'inbox' => [self::Inbox],
            'reports', 'tax-returns', 'budgets' => [self::Reports],
            // Deliberately ungated, like the dashboard the insight card lives
            // on — the history page shows the same aggregate data class as the
            // dashboard widgets. An explicit arm so future insights.* routes
            // are mapped consciously rather than falling through to default.
            'insights' => [],
            'lists' => [self::Lists],
            'documents' => [self::Documents],
            'settings', 'migration' => [self::Settings],
            default => [],
        };
    }
}
