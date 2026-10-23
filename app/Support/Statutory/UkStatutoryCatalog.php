<?php

namespace App\Support\Statutory;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;

/**
 * The lines of a UK small-company set of statutory accounts — the Statement of
 * Financial Position and Statement of Comprehensive Income formats used under
 * FRS 102 Section 1A, which the FRS 105 micro-entity format condenses.
 *
 * Each account maps onto one line (accounts.statutory_line). An account with no
 * explicit line falls back to a default worked out from its subtype and name, so
 * a chart produces sensible accounts out of the box and the mapping only needs
 * touching where the company's accountant presents something differently (a
 * director's loan shown as falling due after more than one year, say).
 *
 * An optional caption (accounts.statutory_label) groups accounts within a line:
 * the expense lines on the income statement, the analysis of debtors and
 * creditors in the notes, and the asset classes of the tangible fixed asset note.
 *
 * @phpstan-type StatutoryLine array{key: string, label: string, statement: string, group: string}
 */
class UkStatutoryCatalog
{
    public const SFP = 'sfp';

    public const SCI = 'sci';

    /**
     * key => [label, statement, group]. Order is presentation order.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const LINES = [
        // ── Statement of Financial Position ──
        'intangible_assets' => ['Intangible assets', self::SFP, 'fixed_assets'],
        'tangible_cost' => ['Tangible fixed assets — cost', self::SFP, 'fixed_assets'],
        'tangible_depreciation' => ['Tangible fixed assets — depreciation', self::SFP, 'fixed_assets'],
        'investments' => ['Investments', self::SFP, 'fixed_assets'],
        'stocks' => ['Stocks', self::SFP, 'current_assets'],
        'debtors' => ['Debtors', self::SFP, 'current_assets'],
        'cash' => ['Cash at bank and in hand', self::SFP, 'current_assets'],
        'creditors_within' => ['Creditors: amounts falling due within one year', self::SFP, 'creditors'],
        'creditors_after' => ['Creditors: amounts falling due after more than one year', self::SFP, 'creditors'],
        'provisions' => ['Provisions for liabilities', self::SFP, 'creditors'],
        'share_capital' => ['Called up share capital', self::SFP, 'equity'],
        'share_premium' => ['Share premium account', self::SFP, 'equity'],
        'revaluation_reserve' => ['Revaluation reserve', self::SFP, 'equity'],
        'other_reserves' => ['Other reserves', self::SFP, 'equity'],
        'retained_earnings' => ['Retained earnings', self::SFP, 'equity'],

        // ── Statement of Comprehensive Income ──
        'turnover' => ['Turnover', self::SCI, 'income'],
        'cost_of_sales' => ['Cost of sales', self::SCI, 'cost_of_sales'],
        'other_operating_income' => ['Other operating income', self::SCI, 'income'],
        'staff_costs' => ['Staff costs', self::SCI, 'expenses'],
        'depreciation' => ['Depreciation', self::SCI, 'expenses'],
        'administrative_expenses' => ['Administrative expenses', self::SCI, 'expenses'],
        'interest_receivable' => ['Interest receivable and similar income', self::SCI, 'finance'],
        'interest_payable' => ['Interest payable and similar expenses', self::SCI, 'finance'],
        'taxation' => ['Tax on profit', self::SCI, 'tax'],
        'dividends' => ['Dividends', self::SCI, 'distribution'],
        'excluded' => ['Not shown in the statutory accounts', self::SCI, 'excluded'],
    ];

    /**
     * @return array<string, StatutoryLine>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::LINES as $key => [$label, $statement, $group]) {
            $out[$key] = ['key' => $key, 'label' => $label, 'statement' => $statement, 'group' => $group];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::LINES);
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::LINES);
    }

    public static function label(string $key): string
    {
        return self::LINES[$key][0] ?? $key;
    }

    /**
     * Select options grouped by statement, for the mapping pickers.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::LINES as $key => [$label, $statement]) {
            $group = $statement === self::SFP ? 'Statement of Financial Position' : 'Statement of Comprehensive Income';
            $out[$group][] = ['value' => $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * The line an account presents on: its explicit mapping, or the default.
     */
    public static function lineFor(Account $account): string
    {
        return self::exists($account->statutory_line)
            ? $account->statutory_line
            : self::defaultLine(self::subtypeOf($account), (string) $account->name);
    }

    /**
     * The caption an account presents under within its line: its explicit
     * caption, or a default worked out from the line and the account's name.
     */
    public static function labelFor(Account $account): string
    {
        $explicit = trim((string) $account->statutory_label);

        if ($explicit !== '') {
            return $explicit;
        }

        return self::defaultLabel(self::lineFor($account), self::subtypeOf($account), (string) $account->name);
    }

    /**
     * The account's subtype as the enum, whether the model hands back the cast
     * enum or the raw column value.
     */
    public static function subtypeOf(Account $account): ?AccountSubtype
    {
        $subtype = $account->getAttribute('subtype');

        return $subtype instanceof AccountSubtype ? $subtype : AccountSubtype::tryFrom((string) $subtype);
    }

    /**
     * The account's type as the enum, whether the model hands back the cast
     * enum or the raw column value.
     */
    public static function typeOf(Account $account): ?AccountType
    {
        $type = $account->getAttribute('type');

        return $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type);
    }

    public static function defaultLine(?AccountSubtype $subtype, string $name): string
    {
        $n = mb_strtolower($name);
        $has = fn (string ...$needles): bool => collect($needles)->contains(fn ($x) => str_contains($n, $x));

        return match ($subtype) {
            AccountSubtype::Bank, AccountSubtype::UndepositedFunds => 'cash',
            AccountSubtype::AccountsReceivable, AccountSubtype::CurrentAsset => 'debtors',
            AccountSubtype::Inventory => 'stocks',
            AccountSubtype::FixedAsset => $has('depreciation', 'amortisation', 'amortization')
                ? 'tangible_depreciation'
                : ($has('goodwill', 'intangible', 'patent', 'trademark', 'trade mark', 'software development') ? 'intangible_assets' : 'tangible_cost'),
            AccountSubtype::OtherAsset => $has('investment') ? 'investments' : ($has('goodwill', 'intangible') ? 'intangible_assets' : 'debtors'),
            AccountSubtype::AccountsPayable, AccountSubtype::CreditCard, AccountSubtype::TaxPayable, AccountSubtype::CurrentLiability => 'creditors_within',
            AccountSubtype::LongTermLiability, AccountSubtype::OtherLiability => $has('provision', 'deferred tax') ? 'provisions' : 'creditors_after',
            AccountSubtype::Equity => match (true) {
                $has('share capital', 'ordinary share', 'called up', 'common stock', 'common share') => 'share_capital',
                $has('share premium', 'paid-in', 'paid in') => 'share_premium',
                $has('revaluation') => 'revaluation_reserve',
                $has('capital redemption', 'reserve') => 'other_reserves',
                $has('dividend') => 'dividends',
                default => 'retained_earnings',
            },
            AccountSubtype::RetainedEarnings, AccountSubtype::UnrestrictedNetAssets, AccountSubtype::RestrictedNetAssets, AccountSubtype::EndowmentNetAssets => 'retained_earnings',
            AccountSubtype::Income => 'turnover',
            AccountSubtype::OtherIncome => $has('interest', 'dividend received', 'investment income', 'finance income') ? 'interest_receivable' : 'other_operating_income',
            AccountSubtype::CostOfGoodsSold => 'cost_of_sales',
            AccountSubtype::Expense, AccountSubtype::OtherExpense => match (true) {
                $has('dividend') => 'dividends',
                $has('corporation tax', 'income tax expense', 'tax on profit') => 'taxation',
                $has('depreciation', 'amortisation', 'amortization') => 'depreciation',
                $has('interest paid', 'loan interest', 'interest expense', 'interest payable', 'mortgage interest') => 'interest_payable',
                $has('wages', 'salar', 'remuneration', 'pension', 'ni contributions', 'national insurance', 'staff', 'payroll') => 'staff_costs',
                default => 'administrative_expenses',
            },
            null => 'administrative_expenses',
        };
    }

    public static function defaultLabel(string $line, ?AccountSubtype $subtype, string $name): string
    {
        $n = mb_strtolower($name);
        $has = fn (string ...$needles): bool => collect($needles)->contains(fn ($x) => str_contains($n, $x));

        return match ($line) {
            'debtors' => match (true) {
                $subtype === AccountSubtype::AccountsReceivable || $has('trade debtor') => 'Trade debtors',
                $has('prepay', 'accrued income') => 'Prepayments and accrued income',
                default => 'Other debtors',
            },
            'creditors_within', 'creditors_after' => match (true) {
                $subtype === AccountSubtype::AccountsPayable || $has('trade creditor') => 'Trade creditors',
                $has('corporation tax') => 'Corporation tax',
                $subtype === AccountSubtype::TaxPayable || $has('vat', 'paye', 'social security') => 'Taxation and social security',
                $has('accrual', 'deferred income') => 'Accruals and deferred income',
                $has('director', 'member') => "Directors' current accounts",
                $subtype === AccountSubtype::CreditCard || $has('overdraft', 'bank loan') => 'Bank loans and overdrafts',
                $has('loan') => 'Other loans',
                default => 'Other creditors',
            },
            'tangible_cost', 'tangible_depreciation', 'intangible_assets' => self::assetClassFromName($name),
            default => $name,
        };
    }

    /**
     * The asset class an account belongs to in the fixed asset note, read from
     * its name: "Computer equipment cost brought forward", "Computer equipment
     * additions at cost" and "Computer equipment accumulated depreciation" all
     * belong to "Computer equipment".
     */
    public static function assetClassFromName(string $name): string
    {
        $class = preg_replace(
            '/\s*[-–—:(]?\s*(at cost|cost\b|additions?\b|accumulated\b|depreciation\b|amorti[sz]ation\b|disposals?\b|brought forward|b\/f|carried forward|c\/f|nbv|net book value).*$/iu',
            '',
            $name,
        );

        $class = trim((string) $class, " \t-–—:(");

        return $class !== '' ? mb_strtoupper(mb_substr($class, 0, 1)).mb_substr($class, 1) : $name;
    }
}
