<?php

namespace App\Services\Insights\Detectors\Concerns;

use Carbon\CarbonImmutable;

/**
 * Display formatting shared by every detector, so candidate facts carry the
 * same whole-dollar shape the dashboard KPI cards use and the AI narrator can
 * quote values verbatim without arithmetic.
 */
trait FormatsInsightFacts
{
    /** Whole-dollar house format, e.g. -123456 → "-$1,235". */
    protected function formatWhole(int $cents): string
    {
        $dollars = (int) round(abs($cents) / 100);

        return ($cents < 0 ? '-$' : '$').number_format($dollars);
    }

    /** Human day for display values, e.g. "June 15". */
    protected function formatDay(CarbonImmutable $date): string
    {
        return $date->format('F j');
    }
}
