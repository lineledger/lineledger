<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

/**
 * QuickBooks-style report date presets, fiscal-year aware. Resolves a preset key
 * into a [start, end] range given the company's fiscal year start month and today.
 * As-of reports (balance sheet, trial balance) use the range's end date.
 */
class ReportDatePresets
{
    /**
     * @return array<string, string> preset key => human label
     */
    public static function options(): array
    {
        return [
            'custom' => 'Custom',
            'this_month' => 'This Month',
            'this_month_to_date' => 'This Month-to-date',
            'this_fiscal_quarter' => 'This Fiscal Quarter',
            'this_fiscal_quarter_to_date' => 'This Fiscal Quarter-to-date',
            'this_fiscal_year' => 'This Fiscal Year',
            'this_fiscal_year_to_date' => 'This Fiscal Year-to-date',
            'last_month' => 'Last Month',
            'last_fiscal_quarter' => 'Last Fiscal Quarter',
            'last_fiscal_year' => 'Last Fiscal Year',
            'last_fiscal_year_to_date' => 'Last Fiscal Year-to-date',
            'all' => 'All',
        ];
    }

    /**
     * Resolve a preset to a [start, end] range. Returns null for 'custom' (and any
     * unknown key), meaning the caller should leave the dates as the user set them.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function resolve(string $key, int $fiscalYearStartMonth, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $fyStart = self::fiscalYearStart($today, $fiscalYearStartMonth);
        $quarterStart = self::fiscalQuarterStart($today, $fiscalYearStartMonth);
        $lastMonth = $today->subMonthNoOverflow();

        return match ($key) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'this_month_to_date' => [$today->startOfMonth(), $today],
            'this_fiscal_quarter' => [$quarterStart, $quarterStart->addMonths(3)->subDay()],
            'this_fiscal_quarter_to_date' => [$quarterStart, $today],
            'this_fiscal_year' => [$fyStart, $fyStart->addYear()->subDay()],
            'this_fiscal_year_to_date' => [$fyStart, $today],
            'last_month' => [$lastMonth->startOfMonth(), $lastMonth->endOfMonth()],
            'last_fiscal_quarter' => [$quarterStart->subMonths(3), $quarterStart->subDay()],
            'last_fiscal_year' => [$fyStart->subYear(), $fyStart->subDay()],
            'last_fiscal_year_to_date' => [$fyStart->subYear(), $today->subYear()],
            'all' => [CarbonImmutable::create(1970, 1, 1), $today],
            default => null,
        };
    }

    public static function fiscalYearStart(CarbonImmutable $date, int $fiscalYearStartMonth): CarbonImmutable
    {
        $year = $date->month >= $fiscalYearStartMonth ? $date->year : $date->year - 1;

        return CarbonImmutable::create($year, $fiscalYearStartMonth, 1);
    }

    public static function fiscalQuarterStart(CarbonImmutable $date, int $fiscalYearStartMonth): CarbonImmutable
    {
        $fyStart = self::fiscalYearStart($date, $fiscalYearStartMonth);
        $monthsIn = (int) $fyStart->diffInMonths($date);

        return $fyStart->addMonths(intdiv($monthsIn, 3) * 3);
    }
}
