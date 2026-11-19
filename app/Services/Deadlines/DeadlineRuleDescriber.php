<?php

namespace App\Services\Deadlines;

use App\Enums\DeadlineFrequency;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Models\Deadline;
use App\Support\Deadlines\DeadlineDates;
use Carbon\CarbonImmutable;

/**
 * Puts a deadline's rule into words, e.g. "Every 3 months from Aug 1, 2026 ·
 * due on the last day of the month after each period ends".
 */
final class DeadlineRuleDescriber
{
    public static function describe(Deadline|DeadlineRule $rule): string
    {
        $rule = $rule instanceof Deadline ? $rule->rule() : $rule;

        $parts = [self::cadence($rule)];

        if ($rule->frequency->hasPeriods()) {
            $parts[] = self::dueRule($rule);
        }

        if ($rule->weekendAdjustment !== WeekendAdjustment::Keep) {
            $parts[] = $rule->weekendAdjustment === WeekendAdjustment::PreviousBusinessDay
                ? __('weekends move to the Friday before')
                : __('weekends move to the Monday after');
        }

        $end = self::end($rule);

        if ($end !== null) {
            $parts[] = $end;
        }

        return implode(' · ', array_filter($parts));
    }

    public static function cadence(DeadlineRule $rule): string
    {
        $date = DeadlineDates::medium($rule->periodAnchor) ?: '—';
        $n = $rule->interval;

        return match ($rule->frequency) {
            DeadlineFrequency::Once => __('Once, on :date', ['date' => DeadlineDates::medium($rule->dueDate) ?: '—']),
            DeadlineFrequency::AnnualDates => __('Every year on :dates', ['dates' => self::annualDates($rule)]),
            DeadlineFrequency::Weekly => $n > 1
                ? __('Every :n weeks from :date', ['n' => $n, 'date' => $date])
                : __('Weekly from :date', ['date' => $date]),
            default => $n > 1
                ? __('Every :n months from :date', ['n' => $n * (int) $rule->frequency->months(), 'date' => $date])
                : match ($rule->frequency) {
                    DeadlineFrequency::Monthly => __('Monthly from :date', ['date' => $date]),
                    DeadlineFrequency::Quarterly => __('Quarterly from :date', ['date' => $date]),
                    DeadlineFrequency::SemiAnnual => __('Every six months from :date', ['date' => $date]),
                    default => __('Yearly from :date', ['date' => $date]),
                },
        };
    }

    /**
     * Whole sentences per case, so each language can phrase them naturally.
     */
    public static function dueRule(DeadlineRule $rule): string
    {
        $months = $rule->dueMonthsAfter;
        $day = self::dayOrdinal((int) $rule->dueDayOfMonth);

        $sentence = match ($rule->dueDayRule) {
            DueDayRule::SameDay => match (true) {
                $months === 0 => __('due when each period ends'),
                default => trans_choice('due :count month after each period ends|due :count months after each period ends', $months, ['count' => $months]),
            },
            DueDayRule::LastDay => match (true) {
                $months === 0 => __('due on the last day of the month each period ends'),
                $months === 1 => __('due on the last day of the month after each period ends'),
                default => __('due on the last day of the :ordinal month after each period ends', ['ordinal' => self::monthOrdinal($months)]),
            },
            DueDayRule::DayOfMonth => match (true) {
                $months === 0 => __('due on the :day of the month each period ends', ['day' => $day]),
                $months === 1 => __('due on the :day of the month after each period ends', ['day' => $day]),
                default => __('due on the :day of the :ordinal month after each period ends', ['day' => $day, 'ordinal' => self::monthOrdinal($months)]),
            },
        };

        if ($rule->dueDaysAfter > 0) {
            $sentence .= ' '.trans_choice('plus :count day|plus :count days', $rule->dueDaysAfter, ['count' => $rule->dueDaysAfter]);
        }

        return $sentence;
    }

    private static function end(DeadlineRule $rule): ?string
    {
        return match ($rule->endType) {
            RecurrenceEndType::Never => null,
            RecurrenceEndType::OnDate => $rule->endDate !== null
                ? __('until :date', ['date' => DeadlineDates::medium($rule->endDate)])
                : null,
            RecurrenceEndType::AfterOccurrences => $rule->maxOccurrences !== null
                ? trans_choice(':count time|:count times', $rule->maxOccurrences, ['count' => $rule->maxOccurrences])
                : null,
        };
    }

    private static function annualDates(DeadlineRule $rule): string
    {
        return collect($rule->annualDates)
            ->map(function (array $date): string {
                $month = CarbonImmutable::create(2001, $date['month'], 1);

                return $date['day'] === 'last'
                    ? __('the last day of :month', ['month' => DeadlineDates::monthName($month)])
                    : DeadlineDates::short($month->setDay(min((int) $date['day'], $month->daysInMonth)));
            })
            ->implode(', ');
    }

    /**
     * A day of the month: "15th" in English; "15" (and "1er") in French.
     */
    public static function dayOrdinal(int $n): string
    {
        if (str_starts_with(app()->getLocale(), 'fr')) {
            return $n === 1 ? '1er' : (string) $n;
        }

        return self::englishOrdinal($n);
    }

    /**
     * A count of months as an ordinal: "6th" in English, "6e" in French.
     */
    public static function monthOrdinal(int $n): string
    {
        if (str_starts_with(app()->getLocale(), 'fr')) {
            return $n === 1 ? '1er' : $n.'e';
        }

        return self::englishOrdinal($n);
    }

    private static function englishOrdinal(int $n): string
    {
        $suffix = match (true) {
            in_array($n % 100, [11, 12, 13], true) => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n.$suffix;
    }
}
