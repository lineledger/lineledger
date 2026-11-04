<?php

namespace App\Enums;

use App\Support\Access\Grant;
use App\Support\Access\LegacyRoleGrants;

/**
 * The roles every company starts with, adapted from QuickBooks Desktop
 * Enterprise's predefined roles to LineLedger's areas. Their names and
 * descriptions are stored in English on each company's `roles` rows and
 * translated on display.
 *
 * Until someone edits one, a predefined role's permissions are read from
 * {@see self::grants()} (copy-on-write), so a role nobody changed picks up
 * new activities in later releases. Full Access and Accountant are exactly
 * today's Admin and Accountant roles.
 */
enum PredefinedRole: string
{
    case FullAccess = 'full_access';
    case Accountant = 'accountant';
    case ExternalAccountant = 'external_accountant';
    case Bookkeeper = 'bookkeeper';
    case AccountsReceivable = 'accounts_receivable';
    case Sales = 'sales';
    case AccountsPayable = 'accounts_payable';
    case Purchasing = 'purchasing';
    case Banking = 'banking';
    case Inventory = 'inventory';
    case PayrollManager = 'payroll_manager';
    case PayrollProcessor = 'payroll_processor';
    case TimeTracking = 'time_tracking';
    case Fundraising = 'fundraising';
    case ViewOnly = 'view_only';

    /**
     * The English name stored on the role (translated on display).
     */
    public function defaultName(): string
    {
        return match ($this) {
            self::FullAccess => 'Full Access',
            self::Accountant => 'Accountant',
            self::ExternalAccountant => 'External Accountant',
            self::Bookkeeper => 'Bookkeeper',
            self::AccountsReceivable => 'Accounts Receivable',
            self::Sales => 'Sales',
            self::AccountsPayable => 'Accounts Payable',
            self::Purchasing => 'Purchasing',
            self::Banking => 'Banking',
            self::Inventory => 'Inventory',
            self::PayrollManager => 'Payroll Manager',
            self::PayrollProcessor => 'Payroll Processor',
            self::TimeTracking => 'Time Tracking',
            self::Fundraising => 'Fundraising',
            self::ViewOnly => 'View-only',
        };
    }

    /**
     * The English description stored on the role (translated on display).
     */
    public function defaultDescription(): string
    {
        return match ($this) {
            self::FullAccess => 'Everything except opening balances, the audit log, and managing users and roles, which stay with the owner.',
            self::Accountant => 'Every working area — sales, purchases, banking, accounting, payroll and reports — but not organization settings.',
            self::ExternalAccountant => 'For an outside accountant: full accounting, reconciliation and reports, and read-only access to everything else except payroll setup.',
            self::Bookkeeper => 'Day-to-day bookkeeping across sales, purchases, banking, accounting and reports, without payroll.',
            self::AccountsReceivable => 'Customers, invoices, payments received and deposits, with sales reports.',
            self::Sales => 'Customers, estimates, sales orders and invoices, without deleting or receiving payments.',
            self::AccountsPayable => 'Vendors, bills, bill payments and cheques, with purchase reports.',
            self::Purchasing => 'Purchase orders and entering bills from vendors, with purchase reports.',
            self::Banking => 'The bank register, imports, deposits, transfers, cheques and reconciliation.',
            self::Inventory => 'Stock on hand, inventory adjustments, items and purchase orders, with inventory reports.',
            self::PayrollManager => 'Everything in payroll and employees, including setup, pay runs and filings.',
            self::PayrollProcessor => 'Running payroll and recording time, without changing payroll setup.',
            self::TimeTracking => 'Recording time and time off for employees.',
            self::Fundraising => 'Donations, receipts, grants and memberships, with fundraising reports.',
            self::ViewOnly => 'Can open and print everything outside payroll and settings, but change nothing.',
        };
    }

    /**
     * The role's permissions: activity value => grant (None omitted).
     *
     * @return array<string, Grant>
     */
    public function grants(): array
    {
        $grants = match ($this) {
            self::FullAccess => LegacyRoleGrants::for(CompanyRole::Admin)->grants(),
            self::Accountant => LegacyRoleGrants::for(CompanyRole::Accountant)->grants(),
            self::ExternalAccountant => self::externalAccountant(),
            self::Bookkeeper => [
                ...self::fullAreas(Section::Customers, Section::Vendors, Section::Banking, Section::Accounting,
                    Section::Inventory, Section::Inbox, Section::Fundraising, Section::Lists, Section::Documents, Section::Reports),
                ...self::pick([Activity::Employees, Activity::Reimbursements], PermissionAction::View),
            ],
            self::AccountsReceivable => [
                ...self::fullAreas(Section::Customers),
                Activity::Deposits->value => Grant::full(),
                ...self::pick([Activity::Items, Activity::TaxCodes], PermissionAction::View),
                ...self::pick([Activity::ReportsSales], PermissionAction::View, PermissionAction::Print),
            ],
            self::Sales => [
                ...self::pick([Activity::Customers], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify, PermissionAction::ViewBalance),
                ...self::pick([Activity::Estimates, Activity::SalesOrders, Activity::Invoices, Activity::SalesReceipts],
                    PermissionAction::View, PermissionAction::Create, PermissionAction::Modify, PermissionAction::Print),
                ...self::pick([Activity::Receipts, Activity::Items], PermissionAction::View),
                ...self::pick([Activity::ReportsSales], PermissionAction::View, PermissionAction::Print),
            ],
            self::AccountsPayable => [
                ...self::fullAreas(Section::Vendors),
                Activity::Cheques->value => Grant::full(),
                Activity::Inbox->value => Grant::full(),
                ...self::pick([Activity::Items, Activity::TaxCodes], PermissionAction::View),
                ...self::pick([Activity::ReportsPurchases], PermissionAction::View, PermissionAction::Print),
            ],
            self::Purchasing => [
                Activity::PurchaseOrders->value => Grant::full(),
                ...self::pick([Activity::Vendors, Activity::Items], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify),
                ...self::pick([Activity::Bills], PermissionAction::View, PermissionAction::Create),
                ...self::pick([Activity::Inventory], PermissionAction::View),
                ...self::pick([Activity::ReportsPurchases], PermissionAction::View, PermissionAction::Print),
            ],
            self::Banking => [
                ...self::fullAreas(Section::Banking),
                ...self::pick([Activity::Accounts], PermissionAction::View, PermissionAction::ViewBalance),
                ...self::pick([Activity::ReportsFinancial], PermissionAction::View, PermissionAction::Print),
            ],
            self::Inventory => [
                ...self::fullAreas(Section::Inventory),
                Activity::Items->value => Grant::full(),
                ...self::pick([Activity::PurchaseOrders], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify),
                ...self::pick([Activity::Vendors], PermissionAction::View),
                ...self::pick([Activity::ReportsInventory], PermissionAction::View, PermissionAction::Print),
            ],
            self::PayrollManager => self::fullAreas(Section::Payroll, Section::Employees),
            self::PayrollProcessor => [
                ...self::pick([Activity::PayRuns], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify, PermissionAction::Print),
                ...self::pick([Activity::PayrollSetup, Activity::Employees], PermissionAction::View),
                Activity::TimeTracking->value => Grant::full(),
                ...self::pick([Activity::TimeOff], PermissionAction::View, PermissionAction::Modify),
                ...self::pick([Activity::PayrollReports], PermissionAction::View, PermissionAction::Print),
            ],
            self::TimeTracking => [
                ...self::pick([Activity::TimeTracking], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify),
                ...self::pick([Activity::TimeOff], PermissionAction::View, PermissionAction::Create),
                ...self::pick([Activity::Employees], PermissionAction::View),
            ],
            self::Fundraising => [
                ...self::fullAreas(Section::Fundraising),
                Activity::Memberships->value => Grant::full(),
                ...self::pick([Activity::Customers], PermissionAction::View, PermissionAction::Create, PermissionAction::Modify, PermissionAction::ViewBalance),
                ...self::pick([Activity::Deposits], PermissionAction::View, PermissionAction::Create),
                ...self::pick([Activity::ReportsFundraising], PermissionAction::View, PermissionAction::Print),
                ...self::pick([Activity::TrackingLists], PermissionAction::View),
            ],
            self::ViewOnly => self::readOnly(fn (Activity $activity): bool => $activity->area() !== Section::Payroll),
        };

        return array_filter($grants, fn (Grant $grant): bool => ! $grant->isNone());
    }

    /**
     * The predefined role an original role converts to; Custom has none (each
     * distinct set of sections becomes a role of its own).
     */
    public static function forLegacy(CompanyRole $role): ?self
    {
        return match ($role) {
            CompanyRole::Admin => self::FullAccess,
            CompanyRole::Accountant => self::Accountant,
            CompanyRole::Owner, CompanyRole::Custom => null,
        };
    }

    /**
     * Full accounting, reconciliation and reports; read-only (with print and
     * balances) everywhere else outside settings and payroll setup.
     *
     * @return array<string, Grant>
     */
    private static function externalAccountant(): array
    {
        $grants = self::readOnly(fn (Activity $activity): bool => $activity !== Activity::PayrollSetup);

        foreach ([...Activity::forArea(Section::Accounting), ...Activity::forArea(Section::Reports), Activity::Reconciliation] as $activity) {
            if (self::isWorking($activity)) {
                $grants[$activity->value] = Grant::full();
            }
        }

        return $grants;
    }

    /**
     * View, Print and View balance (where offered) on every working activity
     * outside Settings that the filter keeps.
     *
     * @param  callable(Activity): bool  $keep
     * @return array<string, Grant>
     */
    private static function readOnly(callable $keep): array
    {
        $grants = [];

        foreach (Activity::cases() as $activity) {
            if (self::isWorking($activity) && $activity->area() !== Section::Settings && $keep($activity)) {
                $grants += self::pick([$activity], PermissionAction::View, PermissionAction::Print, PermissionAction::ViewBalance);
            }
        }

        return $grants;
    }

    /**
     * Full on every working activity in the areas.
     *
     * @return array<string, Grant>
     */
    private static function fullAreas(Section ...$areas): array
    {
        $grants = [];

        foreach ($areas as $area) {
            foreach (Activity::forArea($area) as $activity) {
                if (self::isWorking($activity)) {
                    $grants[$activity->value] = Grant::full();
                }
            }
        }

        return $grants;
    }

    /**
     * The same partial grant on each activity, keeping only the actions each
     * one offers.
     *
     * @param  list<Activity>  $activities
     * @return array<string, Grant>
     */
    private static function pick(array $activities, PermissionAction ...$actions): array
    {
        $grants = [];

        foreach ($activities as $activity) {
            $grants[$activity->value] = Grant::partial(...array_values(array_filter(
                $actions,
                fn (PermissionAction $action): bool => $activity->supports($action),
            )));
        }

        return $grants;
    }

    private static function isWorking(Activity $activity): bool
    {
        return ! $activity->isAdministrative() && ! $activity->isReserved();
    }
}
