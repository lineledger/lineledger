<?php

namespace App\Services\Recurring;

use App\Enums\RecurrenceDayAnchor;
use Carbon\CarbonImmutable;

/**
 * Computes when a recurring schedule should next generate.
 *
 * For month-based cadences the next date is always re-anchored to the schedule's
 * stored anchor, never to the previously clamped result. This preserves the
 * intended anchor across short months: a day_of_month of 31 yields Jan 31 → Feb 28
 * → Mar 31, rather than permanently collapsing to the 28th.
 *
 * The "last day" and "last business day" anchors ({@see RecurrenceDayAnchor})
 * resolve inside the target month, so a quarterly schedule lands on the last
 * (business) day of each quarter-end month. Business days are Monday–Friday;
 * there is no statutory-holiday calendar.
 *
 * Works on any {@see RecurringSchedule} — recurring documents, recurring
 * journal entries and report email schedules share this arithmetic.
 */
class NextRunDateCalculator
{
    /**
     * The first generation date for a freshly created schedule.
     *
     * With a fixed day_of_month the start date *is* the first run. With a
     * "last …" anchor the start date names the first period instead, and the
     * run lands on that month's anchor day (so Oct 31 2026, a Saturday, with
     * "last business day" runs Fri Oct 30).
     */
    public function first(RecurringSchedule $schedule): CarbonImmutable
    {
        $start = $schedule->scheduleStartDate();

        if ($schedule->scheduleFrequency()->monthsToAdd() === null
            || $schedule->scheduleDayAnchor() === RecurrenceDayAnchor::DayOfMonth) {
            return $start;
        }

        return $this->anchorWithin($start, $schedule, $start->day);
    }

    /**
     * The next generation date strictly after the given date.
     */
    public function next(RecurringSchedule $schedule, CarbonImmutable $from): CarbonImmutable
    {
        $months = $schedule->scheduleFrequency()->monthsToAdd();

        if ($months === null) {
            // Weekly cadence — day_of_month does not apply.
            return $from->addWeeks(1);
        }

        $base = $from->addMonthsNoOverflow($months);

        return $this->anchorWithin($base, $schedule, $schedule->scheduleStartDate()->day);
    }

    /**
     * Resolve the schedule's anchor inside the month that $inMonth falls in.
     * $fallbackDay stands in for a missing day_of_month.
     */
    protected function anchorWithin(CarbonImmutable $inMonth, RecurringSchedule $schedule, int $fallbackDay): CarbonImmutable
    {
        $anchor = $schedule->scheduleDayAnchor();

        if ($anchor === RecurrenceDayAnchor::DayOfMonth) {
            $day = $schedule->scheduleDayOfMonth() ?? $fallbackDay;

            return $inMonth->day(min($day, $inMonth->daysInMonth));
        }

        $date = $inMonth->day($inMonth->daysInMonth);

        if ($anchor === RecurrenceDayAnchor::LastBusinessDay) {
            while ($date->isWeekend()) {
                $date = $date->subDay();
            }
        }

        return $date;
    }
}
