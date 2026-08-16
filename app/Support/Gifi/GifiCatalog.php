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
     * code => [label, section key]. Codes are 4-digit GIFI identifiers.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CODES = [
        // ── Current assets (1000–1599) ──
        '1001' => ['Cash and deposits', 'current_assets'],
        '1060' => ['Accounts receivable', 'current_assets'],
        '1067' => ['Allowance for doubtful accounts', 'current_assets'],
        '1120' => ['Inventories', 'current_assets'],
        '1180' => ['Short-term investments', 'current_assets'],
        '1240' => ['Loans and notes receivable', 'current_assets'],
        '1300' => ['Due from shareholder(s)/director(s)', 'current_assets'],
        '1480' => ['Other current assets', 'current_assets'],
        '1484' => ['Prepaid expenses', 'current_assets'],

        // ── Capital assets (1600–2178) ──
        '1600' => ['Land', 'capital_assets'],
        '1680' => ['Buildings', 'capital_assets'],
        '1740' => ['Machinery, equipment, furniture and fixtures', 'capital_assets'],
        '1743' => ['Motor vehicles', 'capital_assets'],
        '1774' => ['Computer equipment and software', 'capital_assets'],
        '1788' => ['Leasehold improvements', 'capital_assets'],
        '2008' => ['Accumulated amortization of tangible assets', 'capital_assets'],

        // ── Other assets (2179–2589) ──
        '2360' => ['Long-term investments', 'other_assets'],
        '2420' => ['Goodwill', 'other_assets'],
        '2589' => ['Other long-term assets', 'other_assets'],

        // ── Current liabilities (2600–2960) ──
        '2600' => ['Bank overdraft', 'current_liabilities'],
        '2620' => ['Accounts payable and accrued liabilities', 'current_liabilities'],
        '2680' => ['Taxes payable', 'current_liabilities'],
        '2700' => ['Short-term debt', 'current_liabilities'],
        '2780' => ['Due to shareholder(s)/director(s)', 'current_liabilities'],
        '2960' => ['Other current liabilities', 'current_liabilities'],

        // ── Long-term liabilities (3000–3450) ──
        '3140' => ['Long-term debt', 'long_term_liabilities'],
        '3220' => ['Deferred income taxes', 'long_term_liabilities'],
        '3300' => ['Due to related parties (long-term)', 'long_term_liabilities'],
        '3450' => ['Other long-term liabilities', 'long_term_liabilities'],

        // ── Shareholder equity (3500–3849) ──
        '3500' => ['Common shares', 'equity'],
        '3520' => ['Preferred shares', 'equity'],
        '3540' => ['Contributed surplus', 'equity'],
        '3849' => ['Retained earnings / deficit', 'equity'],

        // ── Revenue (8000–8299) ──
        '8000' => ['Trade sales of goods and services', 'revenue'],
        '8090' => ['Investment revenue', 'revenue'],
        '8210' => ['Rental revenue', 'revenue'],
        '8230' => ['Other revenue', 'revenue'],

        // ── Cost of sales (8300–8519) ──
        '8320' => ['Purchases / cost of materials', 'cost_of_sales'],
        '8457' => ['Subcontracts', 'cost_of_sales'],
        '8518' => ['Cost of sales', 'cost_of_sales'],

        // ── Operating expenses (8520–9369) ──
        '8520' => ['Advertising and promotion', 'operating_expenses'],
        '8590' => ['Bad debt expense', 'operating_expenses'],
        '8620' => ['Insurance', 'operating_expenses'],
        '8690' => ['Interest and bank charges', 'operating_expenses'],
        '8710' => ['Interest on long-term debt', 'operating_expenses'],
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
        '9281' => ['Vehicle expenses', 'operating_expenses'],
        '9367' => ['Amortization of tangible assets', 'operating_expenses'],
        '9368' => ['Amortization of intangible assets', 'operating_expenses'],
        '9270' => ['Other expenses', 'operating_expenses'],
    ];

    /**
     * Default GIFI code per account subtype. Used to backfill existing charts and
     * to suggest a code when an account has none. Every target code exists above.
     *
     * @var array<string, string>
     */
    private const SUBTYPE_DEFAULTS = [
        AccountSubtype::Bank->value => '1001',
        AccountSubtype::UndepositedFunds->value => '1001',
        AccountSubtype::AccountsReceivable->value => '1060',
        AccountSubtype::Inventory->value => '1120',
        AccountSubtype::CurrentAsset->value => '1480',
        AccountSubtype::FixedAsset->value => '1740',
        AccountSubtype::OtherAsset->value => '2589',
        AccountSubtype::AccountsPayable->value => '2620',
        AccountSubtype::CreditCard->value => '2620',
        AccountSubtype::TaxPayable->value => '2680',
        AccountSubtype::CurrentLiability->value => '2960',
        AccountSubtype::LongTermLiability->value => '3140',
        AccountSubtype::OtherLiability->value => '3450',
        AccountSubtype::Equity->value => '3500',
        AccountSubtype::RetainedEarnings->value => '3849',
        AccountSubtype::UnrestrictedNetAssets->value => '3849',
        AccountSubtype::RestrictedNetAssets->value => '3849',
        AccountSubtype::EndowmentNetAssets->value => '3849',
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
     * @return array<string, GifiEntry>
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
        return self::SUBTYPE_DEFAULTS[$subtype->value] ?? null;
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
            $grouped[$entry['section_label']][] = [
                'value' => $entry['code'],
                'label' => "{$entry['code']} — {$entry['label']}",
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
