<?php

namespace App\Services\Recurring;

use Carbon\CarbonImmutable;

/**
 * Computes when a recurring schedule should next generate.
 *
 * For month-based cadences the next date is always re-anchored to the schedule's
 * stored day_of_month, never to the previously clamped result. This preserves the
 * intended anchor across short months: a day_of_month of 31 yields Jan 31 → Feb 28
 * → Mar 31, rather than permanently collapsing to the 28th.
 *
 * Works on any {@see RecurringSchedule} — recurring documents and recurring
 * journal entries share this arithmetic.
 */
class NextRunDateCalculator
{
    /**
     * The first generation date for a freshly created schedule.
     */
    public function first(RecurringSchedule $schedule): CarbonImmutable
    {
        return $schedule->scheduleStartDate();
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

        $anchor = $schedule->scheduleDayOfMonth() ?? $schedule->scheduleStartDate()->day;
        $day = min($anchor, $base->daysInMonth);

        return $base->day($day);
    }
}
