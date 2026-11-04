<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;

/**
 * What each company page needs: route name → the activities it belongs to and
 * the action loading it takes. EnsureSectionAccess checks it on every request
 * (and every Livewire update, which replays the page's route).
 *
 * Activities come from the route name: an exact entry, else the longest
 * dotted prefix ("payroll.reports" before "payroll"). Several activities mean
 * any one suffices (recurring documents are sales or purchases). The action
 * comes from an exact override, else the name's last segment:
 *
 *   index, show, anything else    → View
 *   create, import                → Create
 *   edit, setup, sections         → Modify
 *   print, pdf, download, preview,
 *   print-cheque                  → Print
 *
 * Writes that don't have a route of their own (void, post, delete, export …)
 * are Livewire methods, gated where they run.
 *
 * Every tenant route must resolve here or be listed in {@see self::UNGATED};
 * RouteActivityCoverageTest fails otherwise.
 */
final class RoutePermissionMap
{
    /**
     * Routes deliberately open to every member of the company.
     */
    public const array UNGATED = ['dashboard', 'insights.index', 'attachments.download', 'companies.edit'];

    /**
     * Route name or dotted prefix → activity, or the activities any one of
     * which grants access.
     *
     * @var array<string, Activity|list<Activity>>
     */
    private const array ACTIVITIES = [
        // Customers & receivables
        'customers' => Activity::Customers,
        'estimates' => Activity::Estimates,
        'sales-orders' => Activity::SalesOrders,
        'invoices' => Activity::Invoices,
        'credit-memos' => Activity::CreditMemos,
        'receipts' => Activity::Receipts,
        'sales-receipts' => Activity::SalesReceipts,
        'invoice-templates' => Activity::InvoiceTemplates,
        'members' => Activity::Memberships,
        'reminders' => Activity::Reminders,
        'recurring' => [Activity::RecurringSales, Activity::RecurringPurchases],

        // Vendors & payables
        'vendors' => Activity::Vendors,
        'bills' => Activity::Bills,
        'bill-payments' => Activity::BillPayments,
        'purchase-orders' => Activity::PurchaseOrders,
        'vendor-credits' => Activity::VendorCredits,
        'expenses' => Activity::Expenses,

        // Employees
        'employees' => Activity::Employees,
        'reimbursements' => Activity::Reimbursements,

        // Payroll
        'payroll' => Activity::PayrollSetup,
        'payroll.reports' => Activity::PayrollReports,
        'payroll.pay-stub' => Activity::PayRuns,
        'payroll.staff-calendar' => Activity::TimeTracking,
        'payroll-schedules' => Activity::PayrollSetup,
        'pay-runs' => Activity::PayRuns,
        'payroll-cheques' => Activity::PayRuns,
        'time-entries' => Activity::TimeTracking,
        'time-off-policies' => Activity::TimeOff,
        'time-off-requests' => Activity::TimeOff,

        // Inventory
        'inventory' => Activity::Inventory,
        'inventory.adjustments' => Activity::InventoryAdjustments,

        // Accounting
        'accounts' => Activity::Accounts,
        'journal' => Activity::Journal,
        'journal-entry-templates' => Activity::JournalEntryTemplates,
        'recurring-journal' => Activity::RecurringJournal,
        'assets' => Activity::Assets,
        'opening-balances' => Activity::OpeningBalances,

        // Banking
        'banking' => Activity::Banking,
        'banking.reconcile' => Activity::Reconciliation,
        'banking.reconciliations' => Activity::Reconciliation,
        'cheques' => Activity::Cheques,
        'deposits' => Activity::Deposits,
        'transfers' => Activity::Transfers,

        // Inbox
        'inbox' => Activity::Inbox,

        // Fundraising
        'donations' => Activity::Donations,
        'donation-receipts' => Activity::DonationReceipts,
        'grants' => Activity::Grants,

        // Reports: company & financial unless listed below
        'reports' => Activity::ReportsFinancial,
        'reports.index' => self::REPORT_ACTIVITIES,
        'reports.memorized' => self::REPORT_ACTIVITIES,
        'reports.print' => self::REPORT_ACTIVITIES,
        'reports.contact-statement' => [Activity::ReportsSales, Activity::ReportsPurchases],
        'reports.ar-aging' => Activity::ReportsSales,
        'reports.open-invoices' => Activity::ReportsSales,
        'reports.unattributed-ar' => Activity::ReportsSales,
        'reports.sales-by-customer' => Activity::ReportsSales,
        'reports.sales-by-customer-detail' => Activity::ReportsSales,
        'reports.sales-by-item' => Activity::ReportsSales,
        'reports.sales-by-rep' => Activity::ReportsSales,
        'reports.sales-by-rep-detail' => Activity::ReportsSales,
        'reports.membership-roster' => Activity::ReportsSales,
        'reports.membership-revenue-by-level' => Activity::ReportsSales,
        'reports.customer-contact-list' => Activity::ReportsSales,
        'reports.ap-aging' => Activity::ReportsPurchases,
        'reports.open-bills' => Activity::ReportsPurchases,
        'reports.vendor-activity' => Activity::ReportsPurchases,
        'reports.form-1099' => Activity::ReportsPurchases,
        'reports.purchases-by-vendor' => Activity::ReportsPurchases,
        'reports.purchases-by-item' => Activity::ReportsPurchases,
        'reports.open-purchase-orders' => Activity::ReportsPurchases,
        'reports.vendor-contact-list' => Activity::ReportsPurchases,
        'reports.inventory-stock-status' => Activity::ReportsInventory,
        'reports.inventory-valuation' => Activity::ReportsInventory,
        'reports.donations-by-donor' => Activity::ReportsFundraising,
        'reports.donations-by-fund' => Activity::ReportsFundraising,
        'reports.grants-summary' => Activity::ReportsFundraising,
        'reports.sales-tax' => Activity::TaxReturns,
        'reports.vat-return' => Activity::TaxReturns,
        'reports.budget-vs-actual' => Activity::Budgets,
        'reports.budget-overview' => Activity::Budgets,
        'reports.budget-vs-actual-by-month' => Activity::Budgets,
        'reports.audit-log' => Activity::AuditLog,
        'tax-returns' => Activity::TaxReturns,
        'budgets' => Activity::Budgets,

        // Lists
        'lists' => Activity::OtherLists,
        'lists.index' => [Activity::Items, Activity::TaxCodes, Activity::TrackingLists, Activity::OtherLists],
        'lists.items' => Activity::Items,
        'lists.item-categories' => Activity::Items,
        'lists.template' => Activity::Items,
        'lists.tax-codes' => Activity::TaxCodes,
        'lists.classifications' => Activity::TrackingLists,
        'lists.locations' => Activity::TrackingLists,
        'lists.funds' => Activity::TrackingLists,

        // Documents
        'documents' => Activity::Documents,

        // Settings
        'settings' => Activity::Preferences,
        'settings.backup-and-export' => Activity::Backup,
        'settings.backup' => Activity::Backup,
        'settings.stripe' => Activity::CompanySettings,
        'migration' => Activity::DataImport,
    ];

    /**
     * Every report activity under Reports: the hub, memorized reports and
     * report printing open for any of them.
     */
    private const array REPORT_ACTIVITIES = [
        Activity::ReportsFinancial, Activity::ReportsSales, Activity::ReportsPurchases,
        Activity::ReportsInventory, Activity::ReportsFundraising, Activity::TaxReturns, Activity::Budgets,
    ];

    /**
     * Route name → action, where the last segment doesn't say it.
     *
     * @var array<string, PermissionAction>
     */
    private const array ACTIONS = [
        'bill-payments.batch' => PermissionAction::Create,
        'settings.stripe.connect' => PermissionAction::Modify,
        'settings.stripe.disconnect' => PermissionAction::Modify,
        'settings.backup.download' => PermissionAction::View,
    ];

    /**
     * Last route-name segment → action.
     *
     * @var array<string, PermissionAction>
     */
    private const array SUFFIXES = [
        'create' => PermissionAction::Create,
        'import' => PermissionAction::Create,
        'edit' => PermissionAction::Modify,
        'setup' => PermissionAction::Modify,
        'sections' => PermissionAction::Modify,
        'print' => PermissionAction::Print,
        'pdf' => PermissionAction::Print,
        'download' => PermissionAction::Print,
        'preview' => PermissionAction::Print,
        'print-cheque' => PermissionAction::Print,
    ];

    /**
     * What loading the route needs: any one of these requirements. Empty for a
     * route open to every member, or one this map doesn't know (a non-company
     * route).
     *
     * @return list<Requirement>
     */
    public static function for(?string $routeName): array
    {
        if ($routeName === null || in_array($routeName, self::UNGATED, true)) {
            return [];
        }

        $action = self::actionFor($routeName);

        return array_map(
            fn (Activity $activity): Requirement => new Requirement($activity, $action),
            self::activitiesFor($routeName),
        );
    }

    /**
     * The activities a route belongs to (any one suffices), or none.
     *
     * @return list<Activity>
     */
    public static function activitiesFor(?string $routeName): array
    {
        if ($routeName === null || $routeName === '' || in_array($routeName, self::UNGATED, true)) {
            return [];
        }

        $key = $routeName;

        while ($key !== '') {
            if (array_key_exists($key, self::ACTIVITIES)) {
                $match = self::ACTIVITIES[$key];

                return is_array($match) ? $match : [$match];
            }

            $cut = strrpos($key, '.');
            $key = $cut === false ? '' : substr($key, 0, $cut);
        }

        return [];
    }

    /**
     * The action loading the route takes.
     */
    public static function actionFor(string $routeName): PermissionAction
    {
        if (array_key_exists($routeName, self::ACTIONS)) {
            return self::ACTIONS[$routeName];
        }

        $segments = explode('.', $routeName);

        return self::SUFFIXES[end($segments)] ?? PermissionAction::View;
    }
}
