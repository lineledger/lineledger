<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

/**
 * Resolves the comparison ("compare prior") period for financial reports.
 *
 * Two bases, matching QuickBooks:
 *  - Prior year: the same dates shifted back one calendar year.
 *  - Prior period: the period immediately preceding the current one, of the
 *    same length (month → prior month, quarter → prior quarter, year → prior
 *    year), derived from the active date preset.
 */
class ComparisonPeriod
{
    public const Off = 'off';

    public const PriorPeriod = 'prior_period';

    public const PriorYear = 'prior_year';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Off => 'Off',
            self::PriorPeriod => 'Prior period',
            self::PriorYear => 'Prior year',
        ];
    }

    public static function isOn(string $basis): bool
    {
        return $basis === self::PriorPeriod || $basis === self::PriorYear;
    }

    /**
     * Human label for a basis ("prior period" / "prior year"), or '' when off.
     */
    public static function label(string $basis): string
    {
        return match ($basis) {
            self::PriorPeriod => 'prior period',
            self::PriorYear => 'prior year',
            default => '',
        };
    }

    /**
     * Comparison range for a date-range report.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null [priorStart, priorEnd], or null when off.
     */
    public static function forRange(CarbonImmutable $start, CarbonImmutable $end, string $basis, string $preset): ?array
    {
        if (! self::isOn($basis)) {
            return null;
        }

        if ($basis === self::PriorYear) {
            return [$start->subYear(), $end->subYear()];
        }

        $months = self::presetUnitMonths($preset);

        if ($months !== null) {
            // Full-period presets begin on the first day of the period, so
            // shifting the start back by whole months never overflows, and the
            // day before the current start is always the prior period's end.
            return [$start->subMonthsNoOverflow($months), $start->subDay()];
        }

        // Custom range: mirror an equal-length block immediately before it.
        $priorEnd = $start->subDay();

        return [$priorEnd->subDays((int) $start->diffInDays($end)), $priorEnd];
    }

    /**
     * Comparison "as of" date for a single-date (balance) report.
     *
     * @param  CarbonImmutable|null  $periodStart  Start of the current period (from the preset); null for custom/all.
     */
    public static function forAsOf(CarbonImmutable $asOf, string $basis, ?CarbonImmutable $periodStart): ?CarbonImmutable
    {
        if (! self::isOn($basis)) {
            return null;
        }

        if ($basis === self::PriorYear) {
            return $asOf->subYear();
        }

        // Prior period: the period end immediately before the current one.
        if ($periodStart !== null) {
            return $periodStart->subDay();
        }

        // Custom as-of: no inherent period length; fall back to one month back.
        return $asOf->subMonthNoOverflow();
    }

    private static function presetUnitMonths(string $preset): ?int
    {
        return match (true) {
            str_contains($preset, 'quarter') => 3,
            str_contains($preset, 'month') => 1,
            str_contains($preset, 'year') => 12,
            default => null,
        };
    }
}
