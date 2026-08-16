<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\Insights\AI\ClaudeInsightNarrator;
use App\Services\Insights\Detectors\CashTrendDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The shared "advisory" fact-pack. Produces the period flow figures (revenue,
 * COGS, gross profit, operating expenses, net income) plus the end-of-period
 * balance snapshot (cash, A/R, A/P, current assets/liabilities, inventory) that
 * the cashflow forecast, ratios, benchmarks, monthly summary, and alert
 * features all read — so every advisory surface agrees with {@see ReportCalculator}
 * and the dashboard to the cent.
 *
 * Every money value is returned as an exact `*_cents` integer AND a pre-formatted
 * whole-dollar `*_display` string in the dashboard/insight house style (via
 * {@see FormatsInsightFacts}), so a downstream AI narrator can quote figures
 * verbatim without arithmetic — see {@see ClaudeInsightNarrator}.
 *
 * Pure read service. Runs with or without the `current_company` binding — every
 * query is scoped explicitly, mirroring the insight detectors.
 *
 * SQLite-safety: period flows are computed as differences of cumulative
 * `<= date` totals rather than a `whereBetween`, because `entry_date` is stored
 * date-only and a `whereBetween` lower bound at midnight silently drops
 * boundary-day rows on SQLite (see the GL date-bound trap in the reporting notes).
 */
final class FinancialMetrics
{
    use FormatsInsightFacts;

    /** Asset subtypes counted as *current* assets for liquidity ratios. */
    private const CURRENT_ASSET_SUBTYPES = [
        AccountSubtype::Bank,
        AccountSubtype::AccountsReceivable,
        AccountSubtype::UndepositedFunds,
        AccountSubtype::Inventory,
        AccountSubtype::CurrentAsset,
    ];

    /** Liability subtypes counted as *current* liabilities for liquidity ratios. */
    private const CURRENT_LIABILITY_SUBTYPES = [
        AccountSubtype::AccountsPayable,
        AccountSubtype::CreditCard,
        AccountSubtype::TaxPayable,
        AccountSubtype::CurrentLiability,
    ];

    /** Cash = bank balances + undeposited funds (mirrors the dashboard KPI, the Cash on Hand report, and CashTrendDetector). */
    public const CASH_SUBTYPES = [
        AccountSubtype::Bank,
        AccountSubtype::UndepositedFunds,
    ];

    public function __construct(private readonly ReportCalculator $calculator) {}

    /**
     * The period fact-pack: flow figures over [$start, $end] (inclusive) and the
     * balance snapshot as of $end. Every money key carries `*_cents` + `*_display`.
     *
     * @return array<string, int|string>
     */
    public function period(Company $company, CarbonInterface $start, CarbonInterface $end): array
    {
        $start = CarbonImmutable::parse($start->toDateString());
        $end = CarbonImmutable::parse($end->toDateString());

        $revenue = $this->flowForType($company, AccountType::Income, $start, $end);
        $totalExpense = $this->flowForType($company, AccountType::Expense, $start, $end);
        $cogs = $this->cogsForPeriod($company, $start, $end);
        $operatingExpense = $totalExpense - $cogs;
        $grossProfit = $revenue - $cogs;
        $netIncome = $revenue - $totalExpense;

        $cash = $this->balanceForSubtypes($company, self::CASH_SUBTYPES, $end);
        $ar = $this->balanceForSubtypes($company, [AccountSubtype::AccountsReceivable], $end);
        $ap = $this->balanceForSubtypes($company, [AccountSubtype::AccountsPayable], $end);
        $inventory = $this->balanceForSubtypes($company, [AccountSubtype::Inventory], $end);
        $currentAssets = $this->balanceForSubtypes($company, self::CURRENT_ASSET_SUBTYPES, $end);
        $currentLiabilities = $this->balanceForSubtypes($company, self::CURRENT_LIABILITY_SUBTYPES, $end);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => (int) $start->diffInDays($end) + 1,
            ...$this->money('revenue', $revenue),
            ...$this->money('cogs', $cogs),
            ...$this->money('gross_profit', $grossProfit),
            ...$this->money('operating_expense', $operatingExpense),
            ...$this->money('net_income', $netIncome),
            ...$this->money('cash', $cash),
            ...$this->money('ar', $ar),
            ...$this->money('ap', $ap),
            ...$this->money('inventory', $inventory),
            ...$this->money('current_assets', $currentAssets),
            ...$this->money('current_liabilities', $currentLiabilities),
        ];
    }

    /**
     * Trailing monthly income / expense / net, oldest → newest, ending with the
     * current (company-local) month. Each month carries `*_cents` + `*_display`
     * for income, expense, and net.
     *
     * @return list<array<string, int|string>>
     */
    public function monthlySeries(Company $company, int $monthsBack = 12): array
    {
        $thisMonthStart = CarbonImmutable::parse($company->currentDateTime()->toDateString())->startOfMonth();

        $series = [];

        for ($i = $monthsBack - 1; $i >= 0; $i--) {
            $monthStart = $thisMonthStart->subMonthsNoOverflow($i);
            $monthEnd = $monthStart->endOfMonth();

            $income = $this->flowForType($company, AccountType::Income, $monthStart, $monthEnd);
            $expense = $this->flowForType($company, AccountType::Expense, $monthStart, $monthEnd);

            $series[] = [
                'month' => $monthStart->toDateString(),
                'label' => $monthStart->format('M Y'),
                ...$this->money('income', $income),
                ...$this->money('expense', $expense),
                ...$this->money('net', $income - $expense),
            ];
        }

        return $series;
    }

    /**
     * Cash on hand as of a date: bank balances + undeposited funds. Matches the
     * dashboard KPI and {@see CashTrendDetector},
     * and is the opening balance the cashflow forecast projects from.
     */
    public function cashOnHand(Company $company, CarbonInterface $asOf): int
    {
        return $this->balanceForSubtypes(
            $company,
            self::CASH_SUBTYPES,
            CarbonImmutable::parse($asOf->toDateString()),
        );
    }

    /**
     * Natural-balance flow for a whole account type over [$start, $end], as the
     * difference of two cumulative `<= date` totals so boundary-day rows survive
     * on SQLite.
     */
    private function flowForType(Company $company, AccountType $type, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->calculator->totalForTypeAsOf($company, $type, $end)
            - $this->calculator->totalForTypeAsOf($company, $type, $start->subDay());
    }

    /**
     * Cost of goods sold for the period — the COGS subtype is folded inside the
     * Expense type, so it's summed per-account as a cumulative `<= date`
     * difference (same SQLite-safe approach as {@see flowForType()}).
     */
    private function cogsForPeriod(Company $company, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $dayBeforeStart = $start->subDay();

        return (int) Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::CostOfGoodsSold->value)
            ->get()
            ->sum(fn (Account $account): int => $this->calculator->balanceAsOf($account, $end)
                - $this->calculator->balanceAsOf($account, $dayBeforeStart));
    }

    /**
     * Sum of natural-balance account balances as of $asOf across the given
     * subtypes (debit-normal assets and credit-normal liabilities both come back
     * positive in their natural direction).
     *
     * @param  list<AccountSubtype>  $subtypes
     */
    private function balanceForSubtypes(Company $company, array $subtypes, CarbonImmutable $asOf): int
    {
        return (int) Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('subtype', array_map(fn (AccountSubtype $subtype): string => $subtype->value, $subtypes))
            ->get()
            ->sum(fn (Account $account): int => $this->calculator->balanceAsOf($account, $asOf));
    }

    /**
     * A money fact pair: the exact cents and the whole-dollar display string.
     *
     * @return array<string, int|string>
     */
    private function money(string $name, int $cents): array
    {
        return [
            "{$name}_cents" => $cents,
            "{$name}_display" => $this->formatWhole($cents),
        ];
    }
}
