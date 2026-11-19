<?php

namespace App\Services\Deadlines;

use App\Enums\DeadlineFrequency;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Models\Deadline;
use Carbon\CarbonImmutable;

/**
 * A deadline's scheduling rule on its own, so the same arithmetic serves a
 * saved {@see Deadline} and the form's live preview of an unsaved one.
 *
 * Periods run back to back from `periodAnchor`, each `interval` × the
 * frequency long. A period's due date is its end, moved `dueMonthsAfter`
 * months on, set to the `dueDayRule` day, then `dueDaysAfter` days on, then
 * off a weekend per `weekendAdjustment`.
 */
final readonly class DeadlineRule
{
    /**
     * @param  list<array{month: int, day: int|string}>  $annualDates
     */
    public function __construct(
        public DeadlineFrequency $frequency,
        public int $interval = 1,
        public ?CarbonImmutable $periodAnchor = null,
        public ?CarbonImmutable $dueDate = null,
        public array $annualDates = [],
        public int $dueMonthsAfter = 0,
        public DueDayRule $dueDayRule = DueDayRule::SameDay,
        public ?int $dueDayOfMonth = null,
        public int $dueDaysAfter = 0,
        public WeekendAdjustment $weekendAdjustment = WeekendAdjustment::Keep,
        public RecurrenceEndType $endType = RecurrenceEndType::Never,
        public ?CarbonImmutable $endDate = null,
        public ?int $maxOccurrences = null,
    ) {}

    public static function fromDeadline(Deadline $deadline): self
    {
        return new self(
            frequency: $deadline->frequency,
            interval: max(1, (int) $deadline->interval),
            periodAnchor: $deadline->period_anchor,
            dueDate: $deadline->due_date,
            annualDates: self::normalizeAnnualDates($deadline->annual_dates ?? []),
            dueMonthsAfter: (int) $deadline->due_months_after,
            dueDayRule: $deadline->due_day_rule,
            dueDayOfMonth: $deadline->due_day_of_month,
            dueDaysAfter: (int) $deadline->due_days_after,
            weekendAdjustment: $deadline->weekend_adjustment,
            endType: $deadline->end_type,
            endDate: $deadline->end_date,
            maxOccurrences: $deadline->max_occurrences,
        );
    }

    /**
     * A rule from SaveDeadline-shaped attributes (the suggestions catalog).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $date = fn (string $key): ?CarbonImmutable => empty($attributes[$key])
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', (string) $attributes[$key]);

        return new self(
            frequency: DeadlineFrequency::from((string) $attributes['frequency']),
            interval: max(1, (int) ($attributes['interval'] ?? 1)),
            periodAnchor: $date('period_anchor'),
            dueDate: $date('due_date'),
            annualDates: self::normalizeAnnualDates($attributes['annual_dates'] ?? []),
            dueMonthsAfter: (int) ($attributes['due_months_after'] ?? 0),
            dueDayRule: DueDayRule::from((string) ($attributes['due_day_rule'] ?? DueDayRule::SameDay->value)),
            dueDayOfMonth: isset($attributes['due_day_of_month']) ? (int) $attributes['due_day_of_month'] : null,
            dueDaysAfter: (int) ($attributes['due_days_after'] ?? 0),
            weekendAdjustment: WeekendAdjustment::from((string) ($attributes['weekend_adjustment'] ?? WeekendAdjustment::Keep->value)),
            endType: RecurrenceEndType::from((string) ($attributes['end_type'] ?? RecurrenceEndType::Never->value)),
            endDate: $date('end_date'),
            maxOccurrences: isset($attributes['max_occurrences']) ? (int) $attributes['max_occurrences'] : null,
        );
    }

    /**
     * Annual dates as `{month, day}` pairs, where day is 1–31 or "last",
     * sorted through the year. Anything malformed is dropped.
     *
     * @param  array<int, mixed>  $dates
     * @return list<array{month: int, day: int|string}>
     */
    public static function normalizeAnnualDates(array $dates): array
    {
        $out = [];

        foreach ($dates as $date) {
            if (! is_array($date)) {
                continue;
            }

            $month = (int) ($date['month'] ?? 0);
            $day = $date['day'] ?? null;

            if ($month < 1 || $month > 12) {
                continue;
            }

            if ($day === 'last') {
                $out[] = ['month' => $month, 'day' => 'last'];

                continue;
            }

            $day = (int) $day;

            if ($day >= 1 && $day <= 31) {
                $out[] = ['month' => $month, 'day' => $day];
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['month'], $a['day'] === 'last' ? 32 : $a['day']]
            <=> [$b['month'], $b['day'] === 'last' ? 32 : $b['day']]);

        return array_values(array_unique($out, SORT_REGULAR));
    }
}
