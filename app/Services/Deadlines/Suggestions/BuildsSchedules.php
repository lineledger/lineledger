<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\DueDayRule;
use App\Enums\WeekendAdjustment;
use Carbon\CarbonImmutable;

/**
 * Shorthand for the schedules the catalogs suggest. Anchors sit a year back
 * so the period now under way is always produced; "track from" on the
 * suggestions page decides which due dates are actually added.
 */
trait BuildsSchedules
{
    /**
     * Back-to-back periods from $anchor, each due $months after it ends.
     *
     * @return array<string, mixed>
     */
    protected static function periods(
        string $frequency,
        CarbonImmutable $anchor,
        int $months,
        DueDayRule $day,
        ?int $dayOfMonth = null,
        int $plusDays = 0,
        WeekendAdjustment $weekend = WeekendAdjustment::Keep,
    ): array {
        return [
            'frequency' => $frequency,
            'period_anchor' => $anchor->toDateString(),
            'due_months_after' => $months,
            'due_day_rule' => $day->value,
            'due_day_of_month' => $day === DueDayRule::DayOfMonth ? $dayOfMonth : null,
            'due_days_after' => $plusDays,
            'weekend_adjustment' => $weekend->value,
        ];
    }

    /**
     * Fixed dates every year, e.g. [[6, 15], [9, 15], [12, 15]] or [[2, 'last']].
     *
     * @param  list<array{0: int, 1: int|string}>  $dates
     * @return array<string, mixed>
     */
    protected static function yearly(array $dates, CarbonImmutable $from, WeekendAdjustment $weekend = WeekendAdjustment::Keep): array
    {
        return [
            'frequency' => 'annual_dates',
            'period_anchor' => $from->toDateString(),
            'annual_dates' => array_map(fn (array $d): array => ['month' => $d[0], 'day' => $d[1]], $dates),
            'weekend_adjustment' => $weekend->value,
        ];
    }

    /**
     * The fiscal year before the current one starts (the anchor for fiscal-year schedules).
     */
    protected static function previousFiscalYearStart(CarbonImmutable $today, int $startMonth): CarbonImmutable
    {
        $year = $today->month >= $startMonth ? $today->year : $today->year - 1;

        return CarbonImmutable::create($year - 1, $startMonth, 1);
    }

    protected static function previousCalendarYearStart(CarbonImmutable $today): CarbonImmutable
    {
        return CarbonImmutable::create($today->year - 1, 1, 1);
    }

    /**
     * The month number $offset months after $startMonth (1–12, wrapping).
     */
    protected static function monthAfter(int $startMonth, int $offset): int
    {
        return (($startMonth - 1 + $offset) % 12) + 1;
    }
}
