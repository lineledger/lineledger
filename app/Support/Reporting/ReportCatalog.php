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
                'label' => 'Company & Financial',
                'reports' => [
                    ['key' => 'reports.balance-sheet', 'label' => 'Balance Sheet', 'description' => 'Your financial position: assets, liabilities, and equity as of a date.', 'icon' => 'scale', 'route' => 'reports.balance-sheet', 'companyScoped' => true],
                    ['key' => 'reports.income-statement', 'label' => 'Income Statement', 'description' => 'Income, expenses, and net profit or loss over a period.', 'icon' => 'chart-bar', 'route' => 'reports.income-statement', 'companyScoped' => true],
                    ['key' => 'reports.profit-insights', 'label' => 'Profit Insights', 'description' => 'What moved your profit versus the prior period — top customers, vendors, and expense categories.', 'icon' => 'sparkles', 'route' => 'reports.profit-insights', 'companyScoped' => true],
                    ['key' => 'reports.cash-flow', 'label' => 'Cash Flow', 'description' => 'Net change in cash from operating, investing, and financing activity.', 'icon' => 'banknotes', 'route' => 'reports.cash-flow', 'companyScoped' => true],
                    ['key' => 'reports.cash-flow-forecast', 'label' => 'Cash Flow Forecast', 'description' => 'Where your cash is headed over the next quarter, from open invoices, bills, and your recent run-rate — with a low-cash alert.', 'icon' => 'arrow-trending-up', 'route' => 'reports.cash-flow-forecast', 'companyScoped' => true],
                    ['key' => 'reports.cash-on-hand', 'label' => 'Cash on Hand', 'description' => 'Every bank and undeposited-funds account that makes up your cash balance.', 'icon' => 'banknotes', 'route' => 'reports.cash-on-hand', 'companyScoped' => true],
                    ['key' => 'reports.budget-vs-actual', 'label' => 'Budget vs. Actual', 'description' => 'Actual results against your budget targets, with variances by account.', 'icon' => 'calculator', 'route' => 'reports.budget-vs-actual', 'companyScoped' => true],
                    ['key' => 'reports.budget-overview', 'label' => 'Budget Overview', 'description' => 'The monthly target amounts entered for a budget.', 'icon' => 'table-cells', 'route' => 'reports.budget-overview', 'companyScoped' => true],
                    ['key' => 'reports.budget-vs-actual-by-month', 'label' => 'Budget vs. Actual by Month', 'description' => 'Month-by-month actual, budget, or variance across the fiscal year.', 'icon' => 'calendar-days', 'route' => 'reports.budget-vs-actual-by-month', 'companyScoped' => true],
                    ['key' => 'reports.management', 'label' => 'Management Reports', 'description' => 'Build a bundled, professional PDF package of reports with a cover page and table of contents.', 'icon' => 'document-duplicate', 'route' => 'reports.management', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Non-profit',
                'reports' => [
                    ['key' => 'reports.statement-of-financial-position', 'label' => 'Statement of Financial Position', 'description' => 'ASNPO balance sheet presenting assets, liabilities, and net assets by class.', 'icon' => 'scale', 'route' => 'reports.statement-of-financial-position', 'companyScoped' => true],
                    ['key' => 'reports.statement-of-operations', 'label' => 'Statement of Operations', 'description' => 'Revenue and expenses with the excess (deficiency) of revenue over expenses.', 'icon' => 'chart-bar', 'route' => 'reports.statement-of-operations', 'companyScoped' => true],
                    ['key' => 'reports.statement-of-changes-in-net-assets', 'label' => 'Statement of Changes in Net Assets', 'description' => 'Opening to closing net assets by class, with the period excess and transfers.', 'icon' => 'arrows-right-left', 'route' => 'reports.statement-of-changes-in-net-assets', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Customers & Receivables',
                'reports' => [
                    ['key' => 'reports.ar-aging', 'label' => 'AR Aging', 'description' => 'Outstanding customer balances bucketed by how overdue they are.', 'icon' => 'clock', 'route' => 'reports.ar-aging', 'companyScoped' => true],
                    ['key' => 'reports.open-invoices', 'label' => 'Open Invoices', 'description' => 'Every unpaid customer invoice and its outstanding balance.', 'icon' => 'document-text', 'route' => 'reports.open-invoices', 'companyScoped' => true],
                    ['key' => 'reports.unattributed-ar', 'label' => 'Unattributed AR', 'description' => 'Receivable movement not linked to an invoice (orphaned charges).', 'icon' => 'question-mark-circle', 'route' => 'reports.unattributed-ar', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Vendors & Payables',
                'reports' => [
                    ['key' => 'reports.ap-aging', 'label' => 'AP Aging', 'description' => 'Outstanding vendor balances bucketed by how overdue they are.', 'icon' => 'clock', 'route' => 'reports.ap-aging', 'companyScoped' => true],
                    ['key' => 'reports.open-bills', 'label' => 'Open Bills', 'description' => 'Every unpaid vendor bill and its outstanding balance.', 'icon' => 'document-text', 'route' => 'reports.open-bills', 'companyScoped' => true],
                    ['key' => 'reports.form-1099', 'label' => '1099 Summary', 'description' => 'Yearly payments to vendors flagged for 1099-NEC reporting.', 'icon' => 'identification', 'route' => 'reports.form-1099', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Sales',
                'reports' => [
                    ['key' => 'reports.sales-by-customer', 'label' => 'Sales by Customer', 'description' => 'Revenue per customer over a period, net of credit memos.', 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-customer', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-customer-detail', 'label' => 'Sales by Customer (Detail)', 'description' => 'Every sales document per customer over a period — invoices and sales receipts, net of credit memos.', 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-customer-detail', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-item', 'label' => 'Sales by Item', 'description' => 'Revenue and quantity sold per item over a period.', 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-item', 'companyScoped' => true],
                    ['key' => 'reports.sales-by-rep', 'label' => 'Sales by Rep', 'description' => 'Revenue per sales rep over a period.', 'icon' => 'currency-dollar', 'route' => 'reports.sales-by-rep', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Purchases',
                'reports' => [
                    ['key' => 'reports.purchases-by-vendor', 'label' => 'Purchases by Vendor', 'description' => 'Spend per vendor over a period, net of vendor credits.', 'icon' => 'shopping-cart', 'route' => 'reports.purchases-by-vendor', 'companyScoped' => true],
                    ['key' => 'reports.purchases-by-item', 'label' => 'Purchases by Item', 'description' => 'Spend and quantity purchased per item over a period.', 'icon' => 'shopping-cart', 'route' => 'reports.purchases-by-item', 'companyScoped' => true],
                    ['key' => 'reports.open-purchase-orders', 'label' => 'Open Purchase Orders', 'description' => 'Purchase orders not yet fully received.', 'icon' => 'clipboard-document-list', 'route' => 'reports.open-purchase-orders', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Inventory',
                'reports' => [
                    ['key' => 'reports.inventory-stock-status', 'label' => 'Stock Status', 'description' => 'On-hand quantities and reorder flags for inventory items.', 'icon' => 'archive-box', 'route' => 'reports.inventory-stock-status', 'companyScoped' => true],
                    ['key' => 'reports.inventory-valuation', 'label' => 'Inventory Valuation', 'description' => 'Current inventory value from remaining FIFO cost layers.', 'icon' => 'cube', 'route' => 'reports.inventory-valuation', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Employees & Payroll',
                'reports' => [
                    ['key' => 'payroll.reports.register', 'label' => 'Payroll Register', 'description' => 'Gross-to-net detail per employee for posted pay runs over a period.', 'icon' => 'table-cells', 'route' => 'payroll.reports.register', 'companyScoped' => true],
                    ['key' => 'payroll.reports.pd7a', 'label' => 'PD7A Remittance', 'description' => 'Federal source deductions and employer contributions to remit to the CRA.', 'icon' => 'document-chart-bar', 'route' => 'payroll.reports.pd7a', 'companyScoped' => true],
                    ['key' => 'payroll.reports.revenu-quebec', 'label' => 'Revenu Québec Remittance', 'description' => 'Quebec source deductions and employer contributions to remit.', 'icon' => 'document-chart-bar', 'route' => 'payroll.reports.revenu-quebec', 'companyScoped' => true],
                    ['key' => 'payroll.reports.workers-comp', 'label' => "Workers' Comp", 'description' => "Workers' compensation assessable earnings and premiums by province.", 'icon' => 'shield-check', 'route' => 'payroll.reports.workers-comp', 'companyScoped' => true],
                    ['key' => 'payroll.reports.remittances', 'label' => 'Remittance History', 'description' => 'Recorded payroll remittances with their periods and payments.', 'icon' => 'clipboard-document-check', 'route' => 'payroll.reports.remittances', 'companyScoped' => true],
                    ['key' => 'payroll.reports.t4', 'label' => 'T4 Slips', 'description' => 'Year-end employment income and deductions, one slip per employee.', 'icon' => 'document-text', 'route' => 'payroll.reports.t4', 'companyScoped' => true],
                    ['key' => 'payroll.reports.t4a', 'label' => 'T4A Slips', 'description' => 'Year-end fees-for-services slips for contractors flagged for T4A.', 'icon' => 'document-text', 'route' => 'payroll.reports.t4a', 'companyScoped' => true],
                    ['key' => 'payroll.reports.rl1', 'label' => 'RL-1 Slips', 'description' => 'Year-end Quebec employment income slips for Quebec employees.', 'icon' => 'document-text', 'route' => 'payroll.reports.rl1', 'companyScoped' => true],
                    ['key' => 'payroll.reports.roe', 'label' => 'Record of Employment', 'description' => 'ROE generation for employees with an interruption of earnings.', 'icon' => 'document-arrow-down', 'route' => 'payroll.reports.roe', 'companyScoped' => true],
                    ['key' => 'payroll.reports.verification', 'label' => 'Calculation Check', 'description' => 'Recompute posted pay runs against current CRA formulas to verify amounts.', 'icon' => 'check-badge', 'route' => 'payroll.reports.verification', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Sales Tax',
                'reports' => [
                    ['key' => 'reports.sales-tax', 'label' => 'Sales Tax', 'description' => 'Tax collected and paid, summarized for your sales tax return.', 'icon' => 'receipt-percent', 'route' => 'reports.sales-tax', 'companyScoped' => true],
                    ['key' => 'tax-returns.index', 'label' => 'Tax Returns', 'description' => 'Filed and draft sales tax returns and their payments.', 'icon' => 'document-check', 'route' => 'tax-returns.index', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Accountant & Taxes',
                'reports' => [
                    ['key' => 'reports.trial-balance', 'label' => 'Trial Balance', 'description' => 'Debit and credit balance of every account on a date.', 'icon' => 'scale', 'route' => 'reports.trial-balance', 'companyScoped' => true],
                    ['key' => 'reports.gifi', 'label' => 'GIFI Statement', 'description' => 'CRA T2 balance sheet (S100) and income statement (S125) by GIFI code.', 'icon' => 'document-chart-bar', 'route' => 'reports.gifi', 'companyScoped' => true],
                    ['key' => 'reports.t5013', 'label' => 'T5013 Partnership', 'description' => 'Partnership GIFI Schedules 100/125 plus the partner income allocation.', 'icon' => 'document-chart-bar', 'route' => 'reports.t5013', 'companyScoped' => true],
                    ['key' => 'reports.t2125', 'label' => 'T2125 Business Activities', 'description' => 'Statement of business or professional activities with the capital cost allowance (CCA) schedule.', 'icon' => 'document-chart-bar', 'route' => 'reports.t2125', 'companyScoped' => true],
                    ['key' => 'reports.t3010', 'label' => 'T3010 Summary', 'description' => 'Registered-charity information return figures: receipted donations, revenue, expenditures, and balance-sheet totals.', 'icon' => 'document-chart-bar', 'route' => 'reports.t3010', 'companyScoped' => true],
                    ['key' => 'reports.general-ledger', 'label' => 'General Ledger', 'description' => 'Every posted transaction, account by account, with running balances.', 'icon' => 'book-open', 'route' => 'reports.general-ledger', 'companyScoped' => true],
                    ['key' => 'reports.audit-log', 'label' => 'Audit Logs', 'description' => 'A tamper-evident trail of accounting and security activity.', 'icon' => 'shield-check', 'route' => 'reports.audit-log', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Membership',
                'reports' => [
                    ['key' => 'reports.membership-roster', 'label' => 'Membership Roster', 'description' => 'Every member with their level, status, and term dates.', 'icon' => 'identification', 'route' => 'reports.membership-roster', 'companyScoped' => true],
                    ['key' => 'reports.membership-revenue-by-level', 'label' => 'Dues Revenue by Level', 'description' => 'Posted dues revenue grouped by membership level over a period.', 'icon' => 'currency-dollar', 'route' => 'reports.membership-revenue-by-level', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Fundraising',
                'reports' => [
                    ['key' => 'reports.donations-by-donor', 'label' => 'Donations by Donor', 'description' => 'Posted donation revenue per donor over a period.', 'icon' => 'gift', 'route' => 'reports.donations-by-donor', 'companyScoped' => true],
                    ['key' => 'reports.donations-by-fund', 'label' => 'Donations by Fund', 'description' => 'Posted donation revenue grouped by restricted fund over a period.', 'icon' => 'rectangle-stack', 'route' => 'reports.donations-by-fund', 'companyScoped' => true],
                    ['key' => 'reports.grants-summary', 'label' => 'Grants Summary', 'description' => 'Each grant with award, recognized-to-date, and deferred balance.', 'icon' => 'banknotes', 'route' => 'reports.grants-summary', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Lists',
                'reports' => [
                    ['key' => 'reports.account-list', 'label' => 'Account List', 'description' => 'Every account in the chart with its type, currency, and balance.', 'icon' => 'book-open-text', 'route' => 'reports.account-list', 'companyScoped' => true],
                    ['key' => 'reports.customer-contact-list', 'label' => 'Customer Contact List', 'description' => 'Customer names, contact details, terms, and open balances.', 'icon' => 'users', 'route' => 'reports.customer-contact-list', 'companyScoped' => true],
                    ['key' => 'reports.vendor-contact-list', 'label' => 'Vendor Contact List', 'description' => 'Vendor names, contact details, terms, and open balances.', 'icon' => 'building-storefront', 'route' => 'reports.vendor-contact-list', 'companyScoped' => true],
                ],
            ],
            [
                'label' => 'Combined / Multi-company',
                'reports' => [
                    ['key' => 'report-groups.index', 'label' => 'Report Groups', 'description' => 'Consolidated statements across multiple companies you manage.', 'icon' => 'building-office-2', 'route' => 'report-groups.index', 'companyScoped' => false],
                ],
            ],
        ];
    }
}
