<?php

namespace App\Services\Reporting;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Liquidity, profitability, and efficiency ratios derived from a
 * {@see FinancialMetrics} pack — current ratio, quick ratio, gross margin, net
 * margin, DSO, DPO, and cash runway. Pure arithmetic over pre-computed cents;
 * every ratio degrades to a null value + an em-dash display when its
 * denominator is zero, so a young or single-sided set of books never divides by
 * zero.
 *
 * Each ratio is returned as `['value' => …, 'display' => '…']` (margins also
 * carry a whole `pct`), so a view or AI narrator can render the friendly string
 * or reason over the raw number.
 */
final class FinancialRatios
{
    public function __construct(private readonly FinancialMetrics $metrics) {}

    /**
     * Convenience: build the metrics pack for the window (with a trailing
     * 3-month series for the runway) and compute the ratios.
     *
     * @return array<string, array<string, float|int|string|null>>
     */
    public function forPeriod(Company $company, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->compute(
            $this->metrics->period($company, $start, $end),
            $this->metrics->monthlySeries($company, 3),
        );
    }

    /**
     * Pure computation over a metrics pack and a trailing monthly series.
     *
     * @param  array<string, int|string>  $period
     * @param  list<array<string, int|string>>  $monthlySeries
     * @return array<string, array<string, float|int|string|null>>
     */
    public function compute(array $period, array $monthlySeries): array
    {
        $currentAssets = (int) ($period['current_assets_cents'] ?? 0);
        $currentLiabilities = (int) ($period['current_liabilities_cents'] ?? 0);
        $inventory = (int) ($period['inventory_cents'] ?? 0);
        $revenue = (int) ($period['revenue_cents'] ?? 0);
        $cogs = (int) ($period['cogs_cents'] ?? 0);
        $operatingExpense = (int) ($period['operating_expense_cents'] ?? 0);
        $grossProfit = (int) ($period['gross_profit_cents'] ?? 0);
        $netIncome = (int) ($period['net_income_cents'] ?? 0);
        $cash = (int) ($period['cash_cents'] ?? 0);
        $ar = (int) ($period['ar_cents'] ?? 0);
        $ap = (int) ($period['ap_cents'] ?? 0);
        $days = max(1, (int) ($period['days'] ?? 1));

        // Payables turn on COGS where there is any; service businesses with no
        // COGS fall back to operating expense so DPO still means something.
        $payableBasis = $cogs > 0 ? $cogs : $operatingExpense;

        return [
            'current_ratio' => $this->times($currentLiabilities > 0 ? $currentAssets / $currentLiabilities : null),
            'quick_ratio' => $this->times($currentLiabilities > 0 ? ($currentAssets - $inventory) / $currentLiabilities : null),
            'gross_margin' => $this->percent($revenue > 0 ? $grossProfit / $revenue : null),
            'net_margin' => $this->percent($revenue > 0 ? $netIncome / $revenue : null),
            'dso_days' => $this->days($revenue > 0 ? $ar * $days / $revenue : null),
            'dpo_days' => $this->days($payableBasis > 0 ? $ap * $days / $payableBasis : null),
            'cash_runway_months' => $this->runway($cash, $monthlySeries),
        ];
    }

    /**
     * A liquidity multiple, e.g. 2.34 → "2.34×".
     *
     * @return array<string, float|string|null>
     */
    private function times(?float $value): array
    {
        if ($value === null) {
            return ['value' => null, 'display' => '—'];
        }

        $rounded = round($value, 2);

        return ['value' => $rounded, 'display' => number_format($rounded, 2).'×'];
    }

    /**
     * A margin percentage, e.g. 0.4231 → 42% → "42%".
     *
     * @return array<string, float|int|string|null>
     */
    private function percent(?float $ratio): array
    {
        if ($ratio === null) {
            return ['value' => null, 'pct' => null, 'display' => '—'];
        }

        $pct = (int) round($ratio * 100);

        return ['value' => round($ratio, 4), 'pct' => $pct, 'display' => $pct.'%'];
    }

    /**
     * A day count, e.g. 38.4 → 38 → "38 days".
     *
     * @return array<string, int|string|null>
     */
    private function days(?float $value): array
    {
        if ($value === null) {
            return ['value' => null, 'display' => '—'];
        }

        $rounded = (int) round($value);

        return ['value' => $rounded, 'display' => $rounded.' '.Str::plural('day', $rounded)];
    }

    /**
     * Months of cash left at the recent average monthly burn. Null when the
     * business isn't burning cash (average monthly net ≥ 0) or has no history.
     *
     * @param  list<array<string, int|string>>  $monthlySeries
     * @return array<string, float|string|null>
     */
    private function runway(int $cash, array $monthlySeries): array
    {
        if ($monthlySeries === []) {
            return ['value' => null, 'display' => '—'];
        }

        $avgNet = array_sum(array_map(
            fn (array $month): int => (int) ($month['net_cents'] ?? 0),
            $monthlySeries,
        )) / count($monthlySeries);

        $burn = -$avgNet; // positive when spending outpaces earning

        if ($burn <= 0) {
            return ['value' => null, 'display' => __('Not burning cash')];
        }

        $months = max(0.0, round($cash / $burn, 1));

        return ['value' => $months, 'display' => $months.' '.Str::plural('month', (int) ceil($months))];
    }
}
