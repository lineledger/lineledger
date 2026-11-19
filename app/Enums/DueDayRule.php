<?php

namespace App\Enums;

/**
 * Which day of the month a due date lands on once the "months after the period
 * ends" offset has been applied.
 */
enum DueDayRule: string
{
    /** Keep the period end's day (clamped to the month's length). */
    case SameDay = 'same_day';
    /** A fixed day of the month (1–31, clamped). */
    case DayOfMonth = 'day_of_month';
    /** The month's last day. */
    case LastDay = 'last_day';

    public function label(): string
    {
        return match ($this) {
            self::SameDay => __('Same day of the month'),
            self::DayOfMonth => __('A set day of the month'),
            self::LastDay => __('Last day of the month'),
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
