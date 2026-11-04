<?php

namespace App\Support\Translation;

/**
 * The predefined roles' names and descriptions, stored in English on each
 * company's roles and registered here so lang/fr.json can translate them on
 * display (see Role::displayName()). Must match PredefinedRole exactly;
 * RoleCopyTest checks.
 */
final class RoleCopy
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            __('Full Access'),
            __('Accountant'),
            __('External Accountant'),
            __('Bookkeeper'),
            __('Accounts Receivable'),
            __('Sales'),
            __('Accounts Payable'),
            __('Purchasing'),
            __('Banking'),
            __('Inventory'),
            __('Payroll Manager'),
            __('Payroll Processor'),
            __('Time Tracking'),
            __('Fundraising'),
            __('View-only'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function descriptions(): array
    {
        return [
            __('Everything except opening balances, the audit log, and managing users and roles, which stay with the owner.'),
            __('Every working area — sales, purchases, banking, accounting, payroll and reports — but not organization settings.'),
            __('For an outside accountant: full accounting, reconciliation and reports, and read-only access to everything else except payroll setup.'),
            __('Day-to-day bookkeeping across sales, purchases, banking, accounting and reports, without payroll.'),
            __('Customers, invoices, payments received and deposits, with sales reports.'),
            __('Customers, estimates, sales orders and invoices, without deleting or receiving payments.'),
            __('Vendors, bills, bill payments and cheques, with purchase reports.'),
            __('Purchase orders and entering bills from vendors, with purchase reports.'),
            __('The bank register, imports, deposits, transfers, cheques and reconciliation.'),
            __('Stock on hand, inventory adjustments, items and purchase orders, with inventory reports.'),
            __('Everything in payroll and employees, including setup, pay runs and filings.'),
            __('Running payroll and recording time, without changing payroll setup.'),
            __('Recording time and time off for employees.'),
            __('Donations, receipts, grants and memberships, with fundraising reports.'),
            __('Can open and print everything outside payroll and settings, but change nothing.'),
        ];
    }
}
