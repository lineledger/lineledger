<?php

namespace App\Enums;

/**
 * Which day of the period a month-based recurring schedule lands on. Weekly
 * cadences ignore the anchor entirely. For quarterly and longer cadences the
 * anchor applies to the last month of each period (quarter-end, year-end, …).
 */
enum RecurrenceDayAnchor: string
{
    /** A fixed calendar day (1–31), clamped to the month's length. */
    case DayOfMonth = 'day_of_month';

    /** The calendar last day of the month (28/29/30/31 as the month dictates). */
    case LastDay = 'last_day';

    /** The last Monday–Friday of the month. Statutory holidays are not skipped. */
    case LastBusinessDay = 'last_business_day';

    public function label(): string
    {
        return match ($this) {
            self::DayOfMonth => 'A specific day of the month',
            self::LastDay => 'Last day of the month',
            self::LastBusinessDay => 'Last business day of the month',
        };
    }

    /**
     * Short form for schedule summaries, e.g. "Quarterly · last business day".
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::DayOfMonth => 'day of month',
            self::LastDay => 'last day',
            self::LastBusinessDay => 'last business day',
        };
    }

    /**
     * Field help shown under the picker.
     */
    public function description(): string
    {
        return match ($this) {
            self::DayOfMonth => 'Days past the end of a short month fall on its last day (31 → Feb 28/29, Apr 30).',
            self::LastDay => 'The month the start date falls in runs first; quarterly and longer cadences use the last month of each period.',
            self::LastBusinessDay => 'The last Monday–Friday of the month; statutory holidays are not skipped. Quarterly and longer cadences use the last month of each period.',
        };
    }

    /**
     * Whether a numeric day_of_month accompanies this anchor.
     */
    public function usesDayOfMonth(): bool
    {
        return $this === self::DayOfMonth;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $a) => ['value' => $a->value, 'label' => $a->label()],
            self::cases(),
        );
    }
}
