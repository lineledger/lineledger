<?php

namespace App\Support\Reporting;

/**
 * Reports that can be rendered outside a Livewire request — the allowlist
 * consumed by ReportRenderer for email, scheduled sends, print, and group
 * bundles. A report absent here simply doesn't offer those features; add an
 * entry once its page component renders cleanly through ReportRenderer.
 */
final class RenderableReports
{
    /**
     * @return array<string, array{component: string, label: string, formats: list<string>}>
     */
    public static function all(): array
    {
        return [
            'reports.income-statement' => [
                'component' => 'pages::reports.income-statement',
                'label' => 'Income Statement',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.balance-sheet' => [
                'component' => 'pages::reports.balance-sheet',
                'label' => 'Balance Sheet',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.cash-flow' => [
                'component' => 'pages::reports.cash-flow',
                'label' => 'Cash Flow Statement',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.trial-balance' => [
                'component' => 'pages::reports.trial-balance',
                'label' => 'Trial Balance',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.ar-aging' => [
                'component' => 'pages::reports.ar-aging',
                'label' => 'AR Aging',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.ap-aging' => [
                'component' => 'pages::reports.ap-aging',
                'label' => 'AP Aging',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.transactions' => [
                'component' => 'pages::reports.transactions',
                'label' => 'Transactions',
                'formats' => ['pdf', 'xlsx'],
            ],
            // The general ledger page has no PDF export (full-history PDFs
            // exhaust dompdf); spreadsheet output only.
            'reports.general-ledger' => [
                'component' => 'pages::reports.general-ledger',
                'label' => 'General Ledger',
                'formats' => ['xlsx'],
            ],
            'reports.account-list' => [
                'component' => 'pages::reports.account-list',
                'label' => 'Account List',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.cash-on-hand' => [
                'component' => 'pages::reports.cash-on-hand',
                'label' => 'Cash on Hand',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.customer-contact-list' => [
                'component' => 'pages::reports.customer-contact-list',
                'label' => 'Customer Contact List',
                'formats' => ['pdf', 'xlsx'],
            ],
            'reports.vendor-contact-list' => [
                'component' => 'pages::reports.vendor-contact-list',
                'label' => 'Vendor Contact List',
                'formats' => ['pdf', 'xlsx'],
            ],
            // Membership-only — the management package builder hides it unless the
            // company tracks membership (the component itself 403s otherwise).
            'reports.membership-roster' => [
                'component' => 'pages::reports.membership-roster',
                'label' => 'Membership List',
                'formats' => ['pdf', 'xlsx'],
            ],
        ];
    }

    /**
     * @return array{component: string, label: string, formats: list<string>}|null
     */
    public static function get(string $reportKey): ?array
    {
        return self::all()[$reportKey] ?? null;
    }

    public static function supports(string $reportKey, string $format): bool
    {
        $entry = self::get($reportKey);

        return $entry !== null && in_array($format, $entry['formats'], true);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
