<?php

namespace App\Enums;

use App\Support\Access\RoutePermissionMap;

/**
 * One unit of access a role is granted — the leaves of the QuickBooks Desktop
 * Enterprise-style "Area and Activities" tree. Each activity belongs to an area
 * (a {@see Section}, the sidebar group it lives in) and offers the actions that
 * make sense for it: invoices can be printed, a tax code cannot; only a few
 * activities show balances.
 *
 * Two kinds of activity are special:
 *  - administrative ones run the organization itself (company settings, the
 *    closing date, API keys, invitations). Full Access grants them; the
 *    legacy section toggles never did.
 *  - reserved ones were Owner-only before roles existed (opening balances,
 *    the audit log, managing users and roles). No predefined role grants
 *    them, so only the Owner holds them until someone builds a role that does.
 *
 * Route names map onto activities in {@see RoutePermissionMap};
 * adding a route prefix means placing it here.
 */
enum Activity: string
{
    /**
     * Bump when activities are added, so `access:sync-roles` gives customized
     * roles their defaults for the new ones (see Activity::since()).
     */
    public const int CATALOG_VERSION = 1;

    // Customers & receivables
    case Customers = 'customers';
    case Estimates = 'estimates';
    case SalesOrders = 'sales-orders';
    case Invoices = 'invoices';
    case CreditMemos = 'credit-memos';
    case Receipts = 'receipts';
    case SalesReceipts = 'sales-receipts';
    case InvoiceTemplates = 'invoice-templates';
    case Memberships = 'memberships';
    case Reminders = 'reminders';
    case RecurringSales = 'recurring-sales';

    // Vendors & payables
    case Vendors = 'vendors';
    case Bills = 'bills';
    case BillPayments = 'bill-payments';
    case PurchaseOrders = 'purchase-orders';
    case VendorCredits = 'vendor-credits';
    case Expenses = 'expenses';
    case RecurringPurchases = 'recurring-purchases';

    // Employees
    case Employees = 'employees';
    case Reimbursements = 'reimbursements';

    // Payroll
    case PayrollSetup = 'payroll-setup';
    case PayRuns = 'pay-runs';
    case PayrollReports = 'payroll-reports';
    case TimeTracking = 'time-tracking';
    case TimeOff = 'time-off';

    // Inventory
    case Inventory = 'inventory';
    case InventoryAdjustments = 'inventory-adjustments';

    // Accounting
    case Accounts = 'accounts';
    case Journal = 'journal';
    case JournalEntryTemplates = 'journal-entry-templates';
    case RecurringJournal = 'recurring-journal';
    case Assets = 'assets';
    case OpeningBalances = 'opening-balances';

    // Banking
    case Banking = 'banking';
    case Reconciliation = 'reconciliation';
    case Cheques = 'cheques';
    case Deposits = 'deposits';
    case Transfers = 'transfers';

    // Inbox
    case Inbox = 'inbox';

    // Fundraising
    case Donations = 'donations';
    case DonationReceipts = 'donation-receipts';
    case Grants = 'grants';

    // Reports
    case ReportsFinancial = 'reports-financial';
    case ReportsSales = 'reports-sales';
    case ReportsPurchases = 'reports-purchases';
    case ReportsInventory = 'reports-inventory';
    case ReportsFundraising = 'reports-fundraising';
    case TaxReturns = 'tax-returns';
    case Budgets = 'budgets';
    case AuditLog = 'audit-log';

    // Lists
    case Items = 'items';
    case TaxCodes = 'tax-codes';
    case TrackingLists = 'tracking-lists';
    case OtherLists = 'other-lists';

    // Documents
    case Documents = 'documents';
    case DocumentsAllFolders = 'documents-all-folders';

    // Settings: the organization itself
    case Preferences = 'preferences';
    case DataImport = 'data-import';
    case Backup = 'backup';
    case CompanySettings = 'company-settings';
    case ClosingDate = 'closing-date';
    case ClosedPeriodChanges = 'closed-period-changes';
    case EditLockTakeover = 'edit-lock-takeover';
    case ApiKeys = 'api-keys';
    case Invitations = 'invitations';
    case Users = 'users';
    case Roles = 'roles';

    /**
     * The area (sidebar group) this activity sits under.
     */
    public function area(): Section
    {
        return match ($this) {
            self::Customers, self::Estimates, self::SalesOrders, self::Invoices, self::CreditMemos,
            self::Receipts, self::SalesReceipts, self::InvoiceTemplates, self::Memberships,
            self::Reminders, self::RecurringSales => Section::Customers,
            self::Vendors, self::Bills, self::BillPayments, self::PurchaseOrders, self::VendorCredits,
            self::Expenses, self::RecurringPurchases => Section::Vendors,
            self::Employees, self::Reimbursements => Section::Employees,
            self::PayrollSetup, self::PayRuns, self::PayrollReports, self::TimeTracking, self::TimeOff => Section::Payroll,
            self::Inventory, self::InventoryAdjustments => Section::Inventory,
            self::Accounts, self::Journal, self::JournalEntryTemplates, self::RecurringJournal,
            self::Assets, self::OpeningBalances => Section::Accounting,
            self::Banking, self::Reconciliation, self::Cheques, self::Deposits, self::Transfers => Section::Banking,
            self::Inbox => Section::Inbox,
            self::Donations, self::DonationReceipts, self::Grants => Section::Fundraising,
            self::ReportsFinancial, self::ReportsSales, self::ReportsPurchases, self::ReportsInventory,
            self::ReportsFundraising, self::TaxReturns, self::Budgets, self::AuditLog => Section::Reports,
            self::Items, self::TaxCodes, self::TrackingLists, self::OtherLists => Section::Lists,
            self::Documents, self::DocumentsAllFolders => Section::Documents,
            self::Preferences, self::DataImport, self::Backup, self::CompanySettings, self::ClosingDate,
            self::ClosedPeriodChanges, self::EditLockTakeover, self::ApiKeys, self::Invitations,
            self::Users, self::Roles => Section::Settings,
        };
    }

    /**
     * The actions this activity offers, in display order. A role can only be
     * granted these; "Full" means all of them.
     *
     * @return list<PermissionAction>
     */
    public function actions(): array
    {
        $v = PermissionAction::View;
        $c = PermissionAction::Create;
        $m = PermissionAction::Modify;
        $d = PermissionAction::Delete;
        $p = PermissionAction::Print;
        $b = PermissionAction::ViewBalance;

        return match ($this) {
            self::Customers, self::Vendors, self::Accounts => [$v, $c, $m, $d, $p, $b],
            self::Employees, self::Banking => [$v, $c, $m, $d, $b],
            self::Inventory => [$v, $p, $b],
            self::Estimates, self::SalesOrders, self::Invoices, self::CreditMemos, self::Receipts,
            self::SalesReceipts, self::Memberships, self::Bills, self::BillPayments, self::PurchaseOrders,
            self::VendorCredits, self::Expenses, self::Reimbursements, self::PayRuns, self::PayrollReports,
            self::Journal, self::Assets, self::Reconciliation, self::Cheques, self::Deposits, self::Transfers,
            self::Donations, self::DonationReceipts, self::Grants, self::TaxReturns, self::Budgets => [$v, $c, $m, $d, $p],
            self::InvoiceTemplates, self::RecurringSales, self::RecurringPurchases, self::PayrollSetup,
            self::TimeTracking, self::TimeOff, self::InventoryAdjustments, self::JournalEntryTemplates,
            self::RecurringJournal, self::Inbox, self::Items, self::TaxCodes, self::TrackingLists,
            self::OtherLists, self::Documents, self::ApiKeys, self::Roles => [$v, $c, $m, $d],
            self::Reminders => [$v, $m, $p],
            self::OpeningBalances => [$v, $c, $m],
            self::ReportsFinancial => [$v, $m, $p],
            self::ReportsSales, self::ReportsPurchases, self::ReportsInventory, self::ReportsFundraising,
            self::AuditLog => [$v, $p],
            self::DocumentsAllFolders, self::Preferences, self::CompanySettings, self::ClosingDate => [$v, $m],
            self::DataImport => [$v, $c],
            self::Backup => [$v],
            self::ClosedPeriodChanges, self::EditLockTakeover => [$m],
            self::Invitations => [$v, $c, $d],
            self::Users => [$v, $m, $d],
        };
    }

    /**
     * Whether a role may be granted the action here at all.
     */
    public function supports(PermissionAction $action): bool
    {
        return in_array($action, $this->actions(), true);
    }

    /**
     * The mask of every action this activity offers — what "Full" grants.
     */
    public function fullMask(): int
    {
        return array_reduce($this->actions(), fn (int $mask, PermissionAction $action): int => $mask | $action->bit(), 0);
    }

    /**
     * Runs the organization itself: granted by Full Access, never by the legacy
     * section toggles (which only ever opened working areas).
     */
    public function isAdministrative(): bool
    {
        return in_array($this, [
            self::DocumentsAllFolders, self::CompanySettings, self::ClosingDate, self::ClosedPeriodChanges,
            self::EditLockTakeover, self::ApiKeys, self::Invitations,
        ], true);
    }

    /**
     * Owner-only before roles existed; no predefined role grants it.
     */
    public function isReserved(): bool
    {
        return in_array($this, [self::OpeningBalances, self::AuditLog, self::Users, self::Roles], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Customers => __('Customers'),
            self::Estimates => __('Estimates'),
            self::SalesOrders => __('Sales orders'),
            self::Invoices => __('Invoices'),
            self::CreditMemos => __('Credit memos'),
            self::Receipts => __('Receive payments'),
            self::SalesReceipts => __('Sales receipts'),
            self::InvoiceTemplates => __('Invoice templates'),
            self::Memberships => __('Memberships'),
            self::Reminders => __('Payment reminders'),
            self::RecurringSales => __('Recurring invoices'),
            self::Vendors => __('Vendors'),
            self::Bills => __('Bills'),
            self::BillPayments => __('Bill payments'),
            self::PurchaseOrders => __('Purchase orders'),
            self::VendorCredits => __('Vendor credits'),
            self::Expenses => __('Expenses'),
            self::RecurringPurchases => __('Recurring bills'),
            self::Employees => __('Employees'),
            self::Reimbursements => __('Reimbursements'),
            self::PayrollSetup => __('Payroll setup'),
            self::PayRuns => __('Pay runs'),
            self::PayrollReports => __('Payroll reports and filings'),
            self::TimeTracking => __('Time tracking'),
            self::TimeOff => __('Time off'),
            self::Inventory => __('Stock on hand'),
            self::InventoryAdjustments => __('Inventory adjustments'),
            self::Accounts => __('Chart of accounts'),
            self::Journal => __('Journal entries'),
            self::JournalEntryTemplates => __('Journal entry templates'),
            self::RecurringJournal => __('Recurring journal entries'),
            self::Assets => __('Fixed assets'),
            self::OpeningBalances => __('Opening balances'),
            self::Banking => __('Bank register, imports and rules'),
            self::Reconciliation => __('Reconciliation'),
            self::Cheques => __('Cheques'),
            self::Deposits => __('Deposits'),
            self::Transfers => __('Transfers'),
            self::Inbox => __('Inbox'),
            self::Donations => __('Donations'),
            self::DonationReceipts => __('Donation receipts'),
            self::Grants => __('Grants'),
            self::ReportsFinancial => __('Company and financial reports'),
            self::ReportsSales => __('Sales and receivables reports'),
            self::ReportsPurchases => __('Purchases and payables reports'),
            self::ReportsInventory => __('Inventory reports'),
            self::ReportsFundraising => __('Fundraising reports'),
            self::TaxReturns => __('Sales tax returns'),
            self::Budgets => __('Budgets'),
            self::AuditLog => __('Audit log'),
            self::Items => __('Items'),
            self::TaxCodes => __('Tax codes'),
            self::TrackingLists => __('Classes, locations and funds'),
            self::OtherLists => __('Other lists'),
            self::Documents => __('Documents'),
            self::DocumentsAllFolders => __('All document folders'),
            self::Preferences => __('Preferences'),
            self::DataImport => __('Data import'),
            self::Backup => __('Backups'),
            self::CompanySettings => __('Organization settings'),
            self::ClosingDate => __('Closing date'),
            self::ClosedPeriodChanges => __('Changes in a closed period'),
            self::EditLockTakeover => __('Take over someone else’s edit'),
            self::ApiKeys => __('API keys'),
            self::Invitations => __('Invitations'),
            self::Users => __('Users'),
            self::Roles => __('Roles'),
        };
    }

    /**
     * The activities in one area, in declaration order.
     *
     * @return list<self>
     */
    public static function forArea(Section $area): array
    {
        return array_values(array_filter(self::cases(), fn (self $activity): bool => $activity->area() === $area));
    }
}
