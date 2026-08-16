<?php

namespace App\Services\Assets;

use App\Models\Asset;
use Carbon\CarbonImmutable;

/**
 * Pure straight-line monthly book-depreciation math for one asset. Month 1 is
 * the calendar month containing the in-service date (full-month convention, no
 * day proration). The depreciable base (cost − salvage) is split with intdiv:
 * months 1..n−1 take the integer base and the final month absorbs the rounding
 * remainder, so the schedule always totals the base exactly.
 *
 * Knows nothing about locks, disposal, or what has already been generated —
 * that filtering belongs to {@see DepreciationGenerator}.
 */
final class DepreciationSchedule
{
    /**
     * The full month-by-month schedule, or an empty list when the math is
     * ineligible (no in-service date, life < 1 month, or net ≤ 0).
     *
     * @return list<array{period: CarbonImmutable, amount_cents: int, cumulative_cents: int}>
     */
    public static function for(Asset $asset): array
    {
        $net = $asset->netCostCents();
        $life = (int) $asset->useful_life_months;

        if ($asset->in_service_date === null || $life < 1 || $net <= 0) {
            return [];
        }

        $base = intdiv($net, $life);
        $start = CarbonImmutable::parse($asset->in_service_date->toDateString())->startOfMonth();

        $rows = [];
        $cumulative = 0;

        for ($month = 1; $month <= $life; $month++) {
            $amount = $month === $life ? $net - $base * ($life - 1) : $base;
            $cumulative += $amount;

            $rows[] = [
                'period' => $start->addMonths($month - 1),
                'amount_cents' => $amount,
                'cumulative_cents' => $cumulative,
            ];
        }

        return $rows;
    }
}
