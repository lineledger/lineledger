<?php

namespace App\Support\Gifi;

use App\Enums\AccountSubtype;
use App\Enums\GifiStatement;

/**
 * The curated catalog of standard CRA GIFI (General Index of Financial
 * Information) codes used to build a GIFI-formatted statement. This is a
 * practical subset of the full ~1,500-code index covering the lines small and
 * mid-sized Canadian companies actually file (Schedule 100 balance sheet and
 * Schedule 125 income statement).
 *
 * Every code and label is copied from CRA's guide RC4088, "General Index of
 * Financial Information (GIFI)"; tests/fixtures/gifi/ holds the names this was
 * checked against, and GifiCatalogTest fails if a label drifts from them. The
 * catalog holds only lines an amount is reported on: CRA's total items (2599
 * Total assets, 9368 Total expenses, …) are left out because tax software
 * computes them. The generic item at the start of a CRA block (1000 Cash and
 * deposits) is not a total, so it is here.
 *
 * Each account in the chart may carry one GIFI code (accounts.gifi_code). The
 * catalog also seeds a sensible default code per account subtype so existing
 * charts get mapped automatically and new accounts get a suggestion.
 *
 * @phpstan-type GifiSection array{key: string, label: string, statement: GifiStatement, half: string, order: int}
 * @phpstan-type GifiEntry array{code: string, label: string, section: string, statement: GifiStatement, half: string, section_label: string, section_order: int}
 */
class GifiCatalog
{
    /**
     * Display/subtotal sections, in render order. `half` groups sections into the
     * balance-sheet halves (assets / liabilities / equity) and income-statement
     * halves (revenue / cogs / expense) that carry their own subtotals.
     *
     * @var array<string, array{label: string, statement: GifiStatement, half: string, order: int}>
     */
    private const SECTIONS = [
        'current_assets' => ['label' => 'Current assets', 'statement' => GifiStatement::BalanceSheet, 'half' => 'assets', 'order' => 10],
        'capital_assets' => ['label' => 'Capital assets', 'statement' => GifiStatement::BalanceSheet, 'half' => 'assets', 'order' => 20],
        'other_assets' => ['label' => 'Other assets', 'statement' => GifiStatement::BalanceSheet, 'half' => 'assets', 'order' => 30],
        'current_liabilities' => ['label' => 'Current liabilities', 'statement' => GifiStatement::BalanceSheet, 'half' => 'liabilities', 'order' => 40],
        'long_term_liabilities' => ['label' => 'Long-term liabilities', 'statement' => GifiStatement::BalanceSheet, 'half' => 'liabilities', 'order' => 50],
        'equity' => ['label' => 'Shareholder equity', 'statement' => GifiStatement::BalanceSheet, 'half' => 'equity', 'order' => 60],
        'revenue' => ['label' => 'Revenue', 'statement' => GifiStatement::IncomeStatement, 'half' => 'revenue', 'order' => 70],
        'cost_of_sales' => ['label' => 'Cost of sales', 'statement' => GifiStatement::IncomeStatement, 'half' => 'cogs', 'order' => 80],
        'operating_expenses' => ['label' => 'Operating expenses', 'statement' => GifiStatement::IncomeStatement, 'half' => 'expense', 'order' => 90],
    ];

    /**
     * code => [label, section key]. Codes are 4-digit GIFI identifiers and
     * labels are CRA's item names from RC4088, verbatim. PHP stores a numeric
     * string key as an int, so the keys are ints at runtime: read the code from
     * an entry's `code`, which {@see self::all()} keeps as a string.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const CODES = [
        // ── Current assets (1000–1599) ──
        '1000' => ['Cash and deposits', 'current_assets'],
        '1001' => ['Cash', 'current_assets'],
        '1060' => ['Accounts receivable', 'current_assets'],
        '1061' => ['Allowance for doubtful accounts', 'current_assets'],
        '1067' => ['Interest receivable', 'current_assets'],
        '1120' => ['Inventories', 'current_assets'],
        '1180' => ['Short-term investments', 'current_assets'],
        '1240' => ['Loans and notes receivable', 'current_assets'],
        '1300' => ['Due from shareholder(s)/director(s)', 'current_assets'],
        '1480' => ['Other current assets', 'current_assets'],
        '1484' => ['Prepaid expenses', 'current_assets'],

        // ── Capital assets (1600–2179) ──
        '1600' => ['Land', 'capital_assets'],
        '1680' => ['Buildings', 'capital_assets'],
        '1681' => ['Accumulated amortization of buildings', 'capital_assets'],
        '1740' => ['Machinery, equipment, furniture, and fixtures', 'capital_assets'],
        '1741' => ['Accumulated amortization of machinery, equipment, furniture, and fixtures', 'capital_assets'],
        '1742' => ['Motor vehicles', 'capital_assets'],
        '1743' => ['Accumulated amortization of motor vehicles', 'capital_assets'],
        '1774' => ['Computer equipment/software', 'capital_assets'],
        '1775' => ['Accumulated amortization of computer equipment/software', 'capital_assets'],
        '1918' => ['Leasehold improvements', 'capital_assets'],
        '1919' => ['Accumulated amortization of leasehold improvements', 'capital_assets'],
        '2010' => ['Intangible assets', 'capital_assets'],
        '2012' => ['Goodwill', 'capital_assets'],

        // ── Long-term assets (2180–2589) ──
        '2300' => ['Long-term investments', 'other_assets'],
        '2360' => ['Long-term loans', 'other_assets'],
        '2420' => ['Other long-term assets', 'other_assets'],

        // ── Current liabilities (2600–3139) ──
        '2600' => ['Bank overdraft', 'current_liabilities'],
        '2620' => ['Amounts payable and accrued liabilities', 'current_liabilities'],
        '2680' => ['Taxes payable', 'current_liabilities'],
        '2700' => ['Short-term debt', 'current_liabilities'],
        '2780' => ['Due to shareholder(s)/director(s)', 'current_liabilities'],
        '2960' => ['Other current liabilities', 'current_liabilities'],

        // ── Long-term liabilities (3140–3450) ──
        '3140' => ['Long-term debt', 'long_term_liabilities'],
        '3220' => ['Deferred income', 'long_term_liabilities'],
        '3240' => ['Future (deferred) income taxes (corporations only)', 'long_term_liabilities'],
        '3300' => ['Due to related parties', 'long_term_liabilities'],
        '3320' => ['Other long-term liabilities', 'long_term_liabilities'],

        // ── Shareholder equity (3500–3640) ──
        '3500' => ['Common shares', 'equity'],
        '3520' => ['Preferred shares', 'equity'],
        '3540' => ['Contributed and other surplus', 'equity'],
        '3600' => ['Retained earnings/deficit', 'equity'],

        // ── Revenue (8000–8299) ──
        '8000' => ['Trade sales of goods and services', 'revenue'],
        '8090' => ['Investment revenue', 'revenue'],
        '8140' => ['Rental revenue', 'revenue'],
        '8210' => ['Realized gains/losses on disposal of assets', 'revenue'],
        '8230' => ['Other revenue', 'revenue'],

        // ── Cost of sales (8300–8519) ──
        // 8518 is "the sum of all cost of sales amounts", but RC4088's own
        // GIFI-Short example reports it on its own, so it takes an amount.
        '8320' => ['Purchases/cost of materials', 'cost_of_sales'],
        '8360' => ['Trades and sub-contracts', 'cost_of_sales'],
        '8457' => ['Freight-in and duty', 'cost_of_sales'],
        '8518' => ['Cost of sales', 'cost_of_sales'],

        // ── Operating expenses (8520–9369) ──
        '8520' => ['Advertising and promotion', 'operating_expenses'],
        '8570' => ['Amortization of intangible assets', 'operating_expenses'],
        '8590' => ['Bad debt expense', 'operating_expenses'],
        '8620' => ['Employee benefits', 'operating_expenses'],
        '8670' => ['Amortization of tangible assets', 'operating_expenses'],
        '8690' => ['Insurance', 'operating_expenses'],
        '8710' => ['Interest and bank charges', 'operating_expenses'],
        '8714' => ['Interest on long-term debt', 'operating_expenses'],
        '8810' => ['Office expenses', 'operating_expenses'],
        '8860' => ['Professional fees', 'operating_expenses'],
        '8910' => ['Rental', 'operating_expenses'],
        '8960' => ['Repairs and maintenance', 'operating_expenses'],
        '9060' => ['Salaries and wages', 'operating_expenses'],
        '9130' => ['Supplies', 'operating_expenses'],
        '9180' => ['Property taxes', 'operating_expenses'],
        '9200' => ['Travel expenses', 'operating_expenses'],
        '9220' => ['Utilities', 'operating_expenses'],
        '9224' => ['Fuel costs', 'operating_expenses'],
        '9270' => ['Other expenses', 'operating_expenses'],
        '9281' => ['Vehicle expenses', 'operating_expenses'],
    ];

    /**
     * Default GIFI code per account subtype. Used to backfill existing charts and
     * to suggest a code when an account has none. Every target code exists above.
     *
     * @var array<string, string>
     */
    private const SUBTYPE_DEFAULTS = [
        AccountSubtype::Bank->value => '1000',
        AccountSubtype::UndepositedFunds->value => '1000',
        AccountSubtype::AccountsReceivable->value => '1060',
        AccountSubtype::Inventory->value => '1120',
        AccountSubtype::CurrentAsset->value => '1480',
        AccountSubtype::FixedAsset->value => '1740',
        AccountSubtype::OtherAsset->value => '2420',
        AccountSubtype::AccountsPayable->value => '2620',
        AccountSubtype::CreditCard->value => '2620',
        AccountSubtype::TaxPayable->value => '2680',
        AccountSubtype::CurrentLiability->value => '2960',
        AccountSubtype::LongTermLiability->value => '3140',
        AccountSubtype::OtherLiability->value => '3320',
        AccountSubtype::Equity->value => '3500',
        AccountSubtype::RetainedEarnings->value => '3600',
        AccountSubtype::UnrestrictedNetAssets->value => '3600',
        AccountSubtype::RestrictedNetAssets->value => '3600',
        AccountSubtype::EndowmentNetAssets->value => '3600',
        AccountSubtype::Income->value => '8000',
        AccountSubtype::OtherIncome->value => '8230',
        AccountSubtype::CostOfGoodsSold->value => '8518',
        AccountSubtype::Expense->value => '9270',
        AccountSubtype::OtherExpense->value => '9270',
    ];

    /**
     * Every catalog entry, enriched with its section metadata, ordered by section
     * then code.
     *
     * Keyed by the code, which PHP stores as an int; each entry's `code` is the
     * string.
     *
     * @return array<int, GifiEntry>
     */
    public static function all(): array
    {
        $entries = [];

        foreach (self::CODES as $code => [$label, $sectionKey]) {
            $section = self::SECTIONS[$sectionKey];

            $entries[(string) $code] = [
                'code' => (string) $code,
                'label' => $label,
                'section' => $sectionKey,
                'section_label' => $section['label'],
                'statement' => $section['statement'],
                'half' => $section['half'],
                'section_order' => $section['order'],
            ];
        }

        uasort($entries, fn (array $a, array $b): int => [$a['section_order'], $a['code']] <=> [$b['section_order'], $b['code']]);

        return $entries;
    }

    /**
     * @return GifiEntry|null
     */
    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map('strval', array_keys(self::CODES));
    }

    public static function statementFor(string $code): ?GifiStatement
    {
        return self::find($code)['statement'] ?? null;
    }

    public static function defaultForSubtype(AccountSubtype $subtype): ?string
    {
        // Every subtype has a default (GifiCatalogTest), so a new subtype
        // without one fails loudly instead of leaving its accounts unmapped.
        return self::SUBTYPE_DEFAULTS[$subtype->value];
    }

    /**
     * Options for a grouped <select>, keyed by section label.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $grouped = [];

        foreach (self::all() as $entry) {
            $grouped[__($entry['section_label'])][] = [
                'value' => $entry['code'],
                'label' => "{$entry['code']} — ".__($entry['label']),
            ];
        }

        return $grouped;
    }

    /**
     * Section definitions in render order.
     *
     * @return list<GifiSection>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $key => $section) {
            $sections[] = ['key' => $key] + $section;
        }

        usort($sections, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $sections;
    }
}
