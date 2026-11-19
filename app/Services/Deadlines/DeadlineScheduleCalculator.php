<?php

namespace App\Services\Deadlines;

use App\Enums\DeadlineFrequency;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use Carbon\CarbonImmutable;

/**
 * Works out the periods and due dates a {@see DeadlineRule} produces. Pure: no
 * database, no clock. Callers pass the window they care about, in the
 * organization's dates.
 *
 * Each period start is computed from the anchor (anchor + n × length), never
 * from the previous period, so a clamped month (Jan 31 → Feb 28) doesn't drift
 * the rest of the series. The same goes for due dates: each is worked out from
 * its own period end.
 */
final class DeadlineScheduleCalculator
{
    /** Hard stop on how many periods one call walks through. */
    private const int MAX_STEPS = 5000;

    /**
     * The occurrences whose (unadjusted) due date falls in [$from, $to],
     * oldest first.
     *
     * @return list<ScheduledOccurrence>
     */
    public function between(DeadlineRule $rule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = self::dateOnly($from);
        $to = self::dateOnly($to);
        $out = [];

        foreach ($this->walk($rule) as $occurrence) {
            if ($occurrence->unadjustedDueDate->gt($to)) {
                // Due dates rise with the period, so nothing later can fall in range.
                break;
            }

            if ($occurrence->unadjustedDueDate->gte($from)) {
                $out[] = $occurrence;
            }
        }

        return $out;
    }

    /**
     * The next `$count` occurrences due on or after `$from`.
     *
     * @return list<ScheduledOccurrence>
     */
    public function upcoming(DeadlineRule $rule, CarbonImmutable $from, int $count): array
    {
        $from = self::dateOnly($from);
        $out = [];

        foreach ($this->walk($rule) as $occurrence) {
            if ($occurrence->unadjustedDueDate->gte($from)) {
                $out[] = $occurrence;

                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The same calendar date as midnight in the app's timezone, so a date that
     * carries the organization's timezone compares exactly with stored dates.
     */
    public static function dateOnly(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString());
    }

    /**
     * The due date for a period ending on `$periodEnd`, before any weekend move.
     */
    public function dueDateFor(DeadlineRule $rule, CarbonImmutable $periodEnd): CarbonImmutable
    {
        $month = $periodEnd->startOfMonth()->addMonthsNoOverflow($rule->dueMonthsAfter);
        $daysInMonth = $month->daysInMonth;

        $day = match ($rule->dueDayRule) {
            DueDayRule::SameDay => min($periodEnd->day, $daysInMonth),
            DueDayRule::DayOfMonth => min(max(1, (int) $rule->dueDayOfMonth), $daysInMonth),
            DueDayRule::LastDay => $daysInMonth,
        };

        return $month->setDay($day)->addDays($rule->dueDaysAfter);
    }

    /**
     * Moves a date off a weekend per the rule.
     */
    public function adjustForWeekend(CarbonImmutable $date, WeekendAdjustment $adjustment): CarbonImmutable
    {
        if (! $date->isWeekend()) {
            return $date;
        }

        return match ($adjustment) {
            WeekendAdjustment::Keep => $date,
            WeekendAdjustment::PreviousBusinessDay => $date->isSaturday() ? $date->subDay() : $date->subDays(2),
            WeekendAdjustment::NextBusinessDay => $date->isSaturday() ? $date->addDays(2) : $date->addDay(),
        };
    }

    /**
     * Every occurrence the rule produces, oldest first, stopping at its end.
     *
     * @return \Generator<int, ScheduledOccurrence>
     */
    private function walk(DeadlineRule $rule): \Generator
    {
        $sequence = 0;

        foreach ($this->raw($rule) as [$periodStart, $periodEnd, $due]) {
            $sequence++;

            if ($rule->endType === RecurrenceEndType::AfterOccurrences
                && $rule->maxOccurrences !== null
                && $sequence > $rule->maxOccurrences) {
                return;
            }

            if ($rule->endType === RecurrenceEndType::OnDate
                && $rule->endDate !== null
                && $due->gt($rule->endDate)) {
                return;
            }

            yield new ScheduledOccurrence(
                sequence: $sequence,
                key: ($periodStart ?? $due)->toDateString(),
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                unadjustedDueDate: $due,
                dueDate: $this->adjustForWeekend($due, $rule->weekendAdjustment),
            );
        }
    }

    /**
     * (period start, period end, unadjusted due) triples, oldest first.
     *
     * @return \Generator<int, array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: CarbonImmutable}>
     */
    private function raw(DeadlineRule $rule): \Generator
    {
        if ($rule->frequency === DeadlineFrequency::Once) {
            if ($rule->dueDate !== null) {
                yield [null, null, self::dateOnly($rule->dueDate)];
            }

            return;
        }

        if ($rule->frequency === DeadlineFrequency::AnnualDates) {
            yield from $this->annualDates($rule);

            return;
        }

        if ($rule->periodAnchor === null) {
            return;
        }

        $anchor = self::dateOnly($rule->periodAnchor);
        $interval = max(1, $rule->interval);
        $months = $rule->frequency->months();

        for ($n = 0; $n < self::MAX_STEPS; $n++) {
            if ($months === null) {
                // Weekly: interval × 7 days per period.
                $start = $anchor->addDays($n * 7 * $interval);
                $next = $anchor->addDays(($n + 1) * 7 * $interval);
            } else {
                $start = $anchor->addMonthsNoOverflow($n * $months * $interval);
                $next = $anchor->addMonthsNoOverflow(($n + 1) * $months * $interval);
            }

            $end = $next->subDay();

            yield [$start, $end, $this->dueDateFor($rule, $end)];
        }
    }

    /**
     * Fixed month/day dates each year, from the anchor's year (or the first
     * date's year when there is no anchor) onwards.
     *
     * @return \Generator<int, array{0: null, 1: null, 2: CarbonImmutable}>
     */
    private function annualDates(DeadlineRule $rule): \Generator
    {
        $dates = $rule->annualDates;

        if ($dates === []) {
            return;
        }

        $from = $rule->periodAnchor ?? $rule->dueDate;

        if ($from === null) {
            return;
        }

        $floor = self::dateOnly($from);
        $startYear = $floor->year;

        for ($year = $startYear; $year < $startYear + 200; $year++) {
            foreach ($dates as $date) {
                $month = CarbonImmutable::create($year, $date['month'], 1);
                $day = $date['day'] === 'last'
                    ? $month->daysInMonth
                    : min((int) $date['day'], $month->daysInMonth);
                $due = $month->setDay($day);

                if ($due->lt($floor)) {
                    continue;
                }

                yield [null, null, $due];
            }
        }
    }
}
