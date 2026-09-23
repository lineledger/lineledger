<?php

namespace App\Support\Reporting;

use App\Enums\Country;
use App\Enums\JurisdictionCapability;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Support\SiteSettings;

/**
 * The directory of reports surfaced on the Report Center hub and the sidebar
 * favorites list. A single source of truth so the two never drift.
 *
 * Each report entry is keyed by its route name and carries display metadata
 * plus a resolved URL. Visibility mirrors the gating that previously lived in
 * the sidebar (US-only 1099, owner-only audit log, payroll behind the same
 * usesPayroll + Payroll-section gate the sidebar uses).
 *
 * Only param-less routes belong here. Contact Statement is intentionally
 * excluded because it requires a {contact} and is reached per-contact.
 *
 * @phpstan-type ReportEntry array{key: string, label: string, description: string, icon: string, route: string, companyScoped: bool, url: string}
 * @phpstan-type ReportCategory array{label: string, reports: list<ReportEntry>}
 */
class ReportCatalog
{
    /**
     * Categories with their visible reports for the given user, each report's
     * URL pre-resolved.
     *
     * @return list<ReportCategory>
     */
    public static function for(Company $company, User $user): array
    {
        $isCanada = $company->jurisdiction === Country::Canada;
        $isOwner = $user->ownsCompany($company);
        $hasInventory = (bool) $company->features_inventory;
        $hasPurchaseOrders = (bool) $company->features_purchase_orders;
        $isNonProfit = $company->organization_type?->isNonProfit() ?? false;
        $isCharity = $company->isRegisteredCharity();
        $hasMembership = $company->tracksMembership();
        $hasFundraising = $company->tracksFundraising();
        $tracksFunds = $company->tracksFunds();
        $hasPayrollReports = $company->usesPayroll()
            && SiteSettings::sectionEnabled(Section::Payroll)
            && $user->canAccessSection($company, Section::Payroll);

        return collect(self::definitions())
            ->map(function (array $category) use ($company, $isOwner, $hasInventory, $hasPurchaseOrders, $isNonProfit, $isCharity, $hasMembership, $hasFundraising, $tracksFunds, $hasPayrollReports): array {
                $category['reports'] = collect($category['reports'])
                    ->filter(fn (array $report): bool => match ($report['key']) {
                        'reports.form-1099' => $company->supports(JurisdictionCapability::Form1099),
                        'reports.gifi' => $company->usesGifi(),
                        'reports.t5013' => $company->filesT5013(),
                        'reports.t2125' => $company->filesT2125(),
                        'reports.audit-log' => $isOwner,
                        'reports.inventory-stock-status', 'reports.inventory-valuation' => $hasInventory,
                        'reports.open-purchase-orders' => $hasPurchaseOrders,
                        'reports.statement-of-financial-position', 'reports.statement-of-operations', 'reports.statement-of-changes-in-net-assets' => $isNonProfit,
                        'reports.t3010' => $isCharity,
                        'reports.membership-roster', 'reports.membership-revenue-by-level' => $hasMembership,
                        'reports.donations-by-donor', 'reports.grants-summary' => $hasFundraising,
                        'reports.donations-by-fund' => $hasFundraising && $tracksFunds,
                        'payroll.reports.register', 'payroll.reports.pd7a', 'payroll.reports.revenu-quebec', 'payroll.reports.workers-comp', 'payroll.reports.remittances', 'payroll.reports.t4', 'payroll.reports.t4a', 'payroll.reports.rl1', 'payroll.reports.roe', 'payroll.reports.verification' => $hasPayrollReports,
                        default => true,
                    })
                    ->map(fn (array $report): array => self::resolve($report, $company))
                    ->values()
                    ->all();

                return $category;
            })
            ->filter(fn (array $category): bool => $category['reports'] !== [])
            ->values()
            ->all();
    }

    /**
     * A flat key => entry map of every report visible to the user. Used by the
     * sidebar favorites list and to validate favorite toggles.
     *
     * @return array<string, ReportEntry>
     */
    public static function flatten(Company $company, User $user): array
    {
        return collect(self::for($company, $user))
            ->flatMap(fn (array $category): array => $category['reports'])
            ->keyBy('key')
            ->all();
    }

    /**
     * @param  array{key: string, label: string, description: string, icon: string, route: string, companyScoped: bool}  $report
     * @return ReportEntry
     */
    protected static function resolve(array $report, Company $company): array
    {
        $report['url'] = $report['companyScoped']
            ? route($report['route'], $company)
            : route($report['route']);

        return $report;
    }

    /**
     * The raw, ungated report directory.
     *
     * @return list<array{label: string, reports: list<array{key: string, label: string, description: string, icon: string, route: string, companyScoped: bool}>}>
     */
    protected static function definitions(): array
    {
        return [
            [
                'label' => __('Company & Financial'),
                'reports' => [
                    ['key' => 'reports.balance-sheet', 'label' => __('Balance Sheet'), 'description' => __('Your financial position: assets, liabilities, and equity as of a date.'), 'icon' => 'scale', 'route' => 'reports.balance-sheet', 'companyScoped' => true],
                    ['key' => 'reports.income-statement', 'label' => __('Income Statement'), 'description' => __('Income, expenses, and net profit or loss over a period.'), 'icon' => 'chart-bar', 'route' => 'reports.income-statement', 'companyScoped' => true],
                    ['key' => 'reports.profit-insights', 'label' => __('Profit Insights'), 'description' => __('What moved your profit versus the prior period — top customers, vendors, and expense categories.'), 'icon' => 'sparkles', 'route' => 'reports.profit-insights', 'companyScoped' => true],
                    ['key' => 'reports.cash-flow', 'label' => __('Cash Flow'), 'description' => __('Net change in cash from operating, investing, and financing activity.'), 'icon' => 'banknotes', 'route' => 'reports.cash-flow', 'companyScoped' => true],
                    ['key' => 'reports.cash-flow-forecast', 'label' => __('Cash Flow Forecast'), 'description' => __('Where your cash is headed over the next quarter, from open invoices, bills, and your recent run-rate — with a low-cash alert.'), 'icon' => 'arrow-trending-up', 'route' => 'reports.cash-flow-forecast', 'companyScoped' => true],
                    ['key' => 'reports.cash-on-hand', 'label' => __('Cash on Hand'), 'description' => __('Every bank and undeposited-funds account that makes up your cash balance.'), 'icon' => 'banknotes', 'route' => 'reports.cash-on-hand', 'companyScoped' => true],
                    ['key' => 'reports.budget-vs-actual', 'label' => __('Budget vs. Actual'), 'description' => __('Actual results against your budget targets, with variances by account.'), 'icon' => 'calculator', 'route' => 'reports.budget-vs-actual', 'companyScoped' => true],
                    ['key' => 'reports.budget-overview', 'label' => __('Budget Overview'), 'description' => __('The monthly target amounts entered for a budget.'), 'icon' => 'table-cells', 'route' => 'reports.budget-overview', 'companyScoped' => true],
                    ['key' => 'reports.budget-vs-actual-by-month', 'label' => __('Budget vs. Actual by Month'), 'description' => __('Month-by-month actual, budget, or variance across the fiscal year.'), 'icon' => 'calendar-days', 'route' => 'reports.budget-vs-actual-by-month', 'companyScoped' => true],
                    ['key' => 'reports.management', 'label' => __('Management Reports'), 'description' => __('Build a bundled, professional PDF package of reports with a cover page and table of contents.'), 'icon' => 'document-duplicate', 'route' => 'reports.management', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Non-profit'),
                'reports' => [
                    ['key' => 'reports.statement-of-financial-position', 'label' => __('Statement of Financial Position'), 'description' => __('ASNPO balance sheet presenting assets, liabilities, and net assets by class.'), 'icon' => 'scale', 'route' => 'reports.statement-of-financial-position', 'companyScoped' => true],
                    ['key' => 'reports.statement-of-operations', 'label' => __('Statement of Operations'), 'description' => __('Revenue and expenses with the excess (deficiency) of revenue over expenses.'), 'icon' => 'chart-bar', 'route' => 'reports.statement-of-operations', 'companyScoped' => true],
                    ['key' => 'reports.statement-of-changes-in-net-assets', 'label' => __('Statement of Changes in Net Assets'), 'description' => __('Opening to closing net assets by class, with the period excess and transfers.'), 'icon' => 'arrows-right-left', 'route' => 'reports.statement-of-changes-in-net-assets', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Customers & Receivables'),
                'reports' => [
                    ['key' => 'reports.ar-aging', 'label' => __('AR Aging'), 'description' => __('Outstanding customer balances bucketed by how overdue they are.'), 'icon' => 'clock', 'route' => 'reports.ar-aging', 'companyScoped' => true],
                    ['key' => 'reports.open-invoices', 'label' => __('Open Invoices'), 'description' => __('Every unpaid customer invoice and its outstanding balance.'), 'icon' => 'document-text', 'route' => 'reports.open-invoices', 'companyScoped' => true],
                    ['key' => 'reports.unattributed-ar', 'label' => __('Unattributed AR'), 'description' => __('Receivable movement not linked to an invoice (orphaned charges).'), 'icon' => 'question-mark-circle', 'route' => 'reports.unattributed-ar', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Vendors & Payables'),
                'reports' => [
                    ['key' => 'reports.ap-aging', 'label' => __('AP Aging'), 'description' => __('Outstanding vendor balances bucketed by how overdue they are.'), 'icon' => 'clock', 'route' => 'reports.ap-aging', 'companyScoped' => true],
                    ['key' => 'reports.open-bills', 'label' => __('Open Bills'), 'description' => __('Every unpaid vendor bill and its outstanding balance.'), 'icon' => 'document-text', 'route' => 'reports.open-bills', 'companyScoped' => true],
                    ['key' => 'reports.vendor-activity', 'label' => __('Vendor Activity'), 'description' => __('Every transaction with a vendor — bills, payments, cheques, and expenses — including payments that never went through Accounts Payable.'), 'icon' => 'list-bullet', 'route' => 'reports.vendor-activity', 'companyScoped' => true],
                    ['key' => 'reports.form-1099', 'label' => __('1099 Summary'), 'description' => __('Yearly payments to vendors flagged for 1099-NEC reporting.'), 'icon' => 'identification', 'route' => 'reports.form-1099', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Sales'),
                'reports' => [
                    ['key' => 'reports.sales-by-customer', 'label' => __('Sales by Customer'), 'description' => __('Revenue per customer over a period, net of credit memos.'), 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-customer', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-customer-detail', 'label' => __('Sales by Customer (Detail)'), 'description' => __('Every sales document per customer over a period — invoices and sales receipts, net of credit memos.'), 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-customer-detail', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-item', 'label' => __('Sales by Item'), 'description' => __('Revenue and quantity sold per item over a period.'), 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-item', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-rep', 'label' => __('Sales by Rep'), 'description' => __('Revenue per sales rep over a period.'), 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-rep', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Purchases'),
                'reports' => [
                    ['key' => 'reports.purchases-by-vendor', 'label' => __('Purchases by Vendor'), 'description' => __('Spend per vendor over a period, net of vendor credits.'), 'icon' => 'shopping-cart', 'route' => 'reports.purchases-by-vendor', 'companyScoped' => true],
                    ['key' => 'reports.purchases-by-item', 'label' => __('Purchases by Item'), 'description' => __('Spend and quantity purchased per item over a period.'), 'icon' => 'shopping-cart', 'route' => 'reports.purchases-by-item', 'companyScoped' => true],
                    ['key' => 'reports.open-purchase-orders', 'label' => __('Open Purchase Orders'), 'description' => __('Purchase orders not yet fully received.'), 'icon' => 'clipboard-document-list', 'route' => 'reports.open-purchase-orders', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Inventory'),
                'reports' => [
                    ['key' => 'reports.inventory-stock-status', 'label' => __('Stock Status'), 'description' => __('On-hand quantities and reorder flags for inventory items.'), 'icon' => 'archive-box', 'route' => 'reports.inventory-stock-status', 'companyScoped' => true],
                    ['key' => 'reports.inventory-valuation', 'label' => __('Inventory Valuation'), 'description' => __('Current inventory value from remaining FIFO cost layers.'), 'icon' => 'cube', 'route' => 'reports.inventory-valuation', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Employees & Payroll'),
                'reports' => [
                    ['key' => 'payroll.reports.register', 'label' => __('Payroll Register'), 'description' => __('Gross-to-net detail per employee for posted pay runs over a period.'), 'icon' => 'table-cells', 'route' => 'payroll.reports.register', 'companyScoped' => true],
                    ['key' => 'payroll.reports.pd7a', 'label' => __('PD7A Remittance'), 'description' => __('Federal source deductions and employer contributions to remit to the CRA.'), 'icon' => 'document-chart-bar', 'route' => 'payroll.reports.pd7a', 'companyScoped' => true],
                    ['key' => 'payroll.reports.revenu-quebec', 'label' => __('Revenu Québec Remittance'), 'description' => __('Quebec source deductions and employer contributions to remit.'), 'icon' => 'document-chart-bar', 'route' => 'payroll.reports.revenu-quebec', 'companyScoped' => true],
                    ['key' => 'payroll.reports.workers-comp', 'label' => __('Workers\' Comp'), 'description' => __('Workers\' compensation assessable earnings and premiums by province.'), 'icon' => 'shield-check', 'route' => 'payroll.reports.workers-comp', 'companyScoped' => true],
                    ['key' => 'payroll.reports.remittances', 'label' => __('Remittance History'), 'description' => __('Recorded payroll remittances with their periods and payments.'), 'icon' => 'clipboard-document-check', 'route' => 'payroll.reports.remittances', 'companyScoped' => true],
                    ['key' => 'payroll.reports.t4', 'label' => __('T4 Slips'), 'description' => __('Year-end employment income and deductions, one slip per employee.'), 'icon' => 'document-text', 'route' => 'payroll.reports.t4', 'companyScoped' => true],
                    ['key' => 'payroll.reports.t4a', 'label' => __('T4A Slips'), 'description' => __('Year-end fees-for-services slips for contractors flagged for T4A.'), 'icon' => 'document-text', 'route' => 'payroll.reports.t4a', 'companyScoped' => true],
                    ['key' => 'payroll.reports.rl1', 'label' => __('RL-1 Slips'), 'description' => __('Year-end Quebec employment income slips for Quebec employees.'), 'icon' => 'document-text', 'route' => 'payroll.reports.rl1', 'companyScoped' => true],
                    ['key' => 'payroll.reports.roe', 'label' => __('Record of Employment'), 'description' => __('ROE generation for employees with an interruption of earnings.'), 'icon' => 'document-arrow-down', 'route' => 'payroll.reports.roe', 'companyScoped' => true],
                    ['key' => 'payroll.reports.verification', 'label' => __('Calculation Check'), 'description' => __('Recompute posted pay runs against current CRA formulas to verify amounts.'), 'icon' => 'check-badge', 'route' => 'payroll.reports.verification', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Sales Tax'),
                'reports' => [
                    ['key' => 'reports.sales-tax', 'label' => __('Sales Tax'), 'description' => __('Tax collected and paid, summarized for your sales tax return.'), 'icon' => 'receipt-percent', 'route' => 'reports.sales-tax', 'companyScoped' => true],
                    ['key' => 'tax-returns.index', 'label' => __('Tax Returns'), 'description' => __('Filed and draft sales tax returns and their payments.'), 'icon' => 'document-check', 'route' => 'tax-returns.index', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Accountant & Taxes'),
                'reports' => [
                    ['key' => 'reports.trial-balance', 'label' => __('Trial Balance'), 'description' => __('Debit and credit balance of every account on a date.'), 'icon' => 'scale', 'route' => 'reports.trial-balance', 'companyScoped' => true],
                    ['key' => 'reports.gifi', 'label' => __('GIFI Statement'), 'description' => __('CRA T2 balance sheet (S100) and income statement (S125) by GIFI code.'), 'icon' => 'document-chart-bar', 'route' => 'reports.gifi', 'companyScoped' => true],
                    ['key' => 'reports.t5013', 'label' => __('T5013 Partnership'), 'description' => __('Partnership GIFI Schedules 100/125 plus the partner income allocation.'), 'icon' => 'document-chart-bar', 'route' => 'reports.t5013', 'companyScoped' => true],
                    ['key' => 'reports.t2125', 'label' => __('T2125 Business Activities'), 'description' => __('Statement of business or professional activities with the capital cost allowance (CCA) schedule.'), 'icon' => 'document-chart-bar', 'route' => 'reports.t2125', 'companyScoped' => true],
                    ['key' => 'reports.t3010', 'label' => __('T3010 Summary'), 'description' => __('Registered-charity information return figures: receipted donations, revenue, expenditures, and balance-sheet totals.'), 'icon' => 'document-chart-bar', 'route' => 'reports.t3010', 'companyScoped' => true],
                    ['key' => 'reports.general-ledger', 'label' => __('General Ledger'), 'description' => __('Every posted transaction, account by account, with running balances.'), 'icon' => 'book-open', 'route' => 'reports.general-ledger', 'companyScoped' => true],
                    ['key' => 'reports.audit-log', 'label' => __('Audit Logs'), 'description' => __('A tamper-evident trail of accounting and security activity.'), 'icon' => 'shield-check', 'route' => 'reports.audit-log', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Membership'),
                'reports' => [
                    ['key' => 'reports.membership-roster', 'label' => __('Membership Roster'), 'description' => __('Every member with their level, status, and term dates.'), 'icon' => 'identification', 'route' => 'reports.membership-roster', 'companyScoped' => true],
                    ['key' => 'reports.membership-revenue-by-level', 'label' => __('Dues Revenue by Level'), 'description' => __('Posted dues revenue grouped by membership level over a period.'), 'icon' => 'currency-dollar', 'route' => 'reports.membership-revenue-by-level', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Fundraising'),
                'reports' => [
                    ['key' => 'reports.donations-by-donor', 'label' => __('Donations by Donor'), 'description' => __('Posted donation revenue per donor over a period.'), 'icon' => 'gift', 'route' => 'reports.donations-by-donor', 'companyScoped' => true],
                    ['key' => 'reports.donations-by-fund', 'label' => __('Donations by Fund'), 'description' => __('Posted donation revenue grouped by restricted fund over a period.'), 'icon' => 'rectangle-stack', 'route' => 'reports.donations-by-fund', 'companyScoped' => true],
                    ['key' => 'reports.grants-summary', 'label' => __('Grants Summary'), 'description' => __('Each grant with award, recognized-to-date, and deferred balance.'), 'icon' => 'banknotes', 'route' => 'reports.grants-summary', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Lists'),
                'reports' => [
                    ['key' => 'reports.account-list', 'label' => __('Account List'), 'description' => __('Every account in the chart with its type, currency, and balance.'), 'icon' => 'book-open-text', 'route' => 'reports.account-list', 'companyScoped' => true],
                    ['key' => 'reports.customer-contact-list', 'label' => __('Customer Contact List'), 'description' => __('Customer names, contact details, terms, and open balances.'), 'icon' => 'users', 'route' => 'reports.customer-contact-list', 'companyScoped' => true],
                    ['key' => 'reports.vendor-contact-list', 'label' => __('Vendor Contact List'), 'description' => __('Vendor names, contact details, terms, and open balances.'), 'icon' => 'building-storefront', 'route' => 'reports.vendor-contact-list', 'companyScoped' => true],
                ],
            ],
            [
                'label' => __('Combined / Multi-company'),
                'reports' => [
                    ['key' => 'report-groups.index', 'label' => __('Report Groups'), 'description' => __('Consolidated statements across multiple companies you manage.'), 'icon' => 'building-office-2', 'route' => 'report-groups.index', 'companyScoped' => false],
                ],
            ],
        ];
    }
}
