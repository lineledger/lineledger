<?php

namespace App\Enums;

use App\Support\Access\RoutePermissionMap;

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
     * Resolve a route name to the section(s) that gate it: the areas of the
     * activities it belongs to ({@see RoutePermissionMap}), so the section
     * kill switch and the permission check read one table.
     *
     * Returns an empty array for ungated routes (e.g. the dashboard or
     * attachment downloads) and for names the map doesn't know. Most routes
     * map to a single section; recurring documents are shared by sales and
     * purchases, so access is granted when the user can reach either
     * Customers or Vendors.
     *
     * @return array<int, self>
     */
    public static function forRouteName(?string $name): array
    {
        $sections = [];

        foreach (RoutePermissionMap::activitiesFor($name) as $activity) {
            $area = $activity->area();

            if (! in_array($area, $sections, true)) {
                $sections[] = $area;
            }
        }

        return $sections;
    }
}
