<?php

namespace App\Services\Insights\Detectors\Concerns;

use App\Models\Company;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * Display formatting shared by every detector, so candidate facts carry the
 * same whole-dollar shape the dashboard KPI cards use and the AI narrator can
 * quote values verbatim without arithmetic.
 */
trait FormatsInsightFacts
{
    /** The company's home-currency symbol; "$" until {@see useCurrencyOf()} runs. */
    protected string $insightCurrencySymbol = '$';

    /** Format amounts in this company's home currency (e.g. "£" for GBP). */
    protected function useCurrencyOf(Company $company): void
    {
        $this->insightCurrencySymbol = Currency::symbol((string) ($company->currency_code ?: 'USD'));
    }

    /** Whole-unit house format, e.g. -123456 → "-$1,235" (or "-£1,235"). */
    protected function formatWhole(int $cents): string
    {
        $units = (int) round(abs($cents) / 100);

        return ($cents < 0 ? '-' : '').$this->insightCurrencySymbol.number_format($units);
    }

    /** Human day for display values, e.g. "June 15". */
    protected function formatDay(CarbonImmutable $date): string
    {
        return $date->format('F j');
    }
}
