<?php

namespace App\Enums;

/**
 * What to do when a due date falls on a Saturday or Sunday. Statutory holidays
 * aren't known to the app, so only weekends move.
 */
enum WeekendAdjustment: string
{
    case Keep = 'keep';
    case PreviousBusinessDay = 'previous_business_day';
    case NextBusinessDay = 'next_business_day';

    public function label(): string
    {
        return match ($this) {
            self::Keep => __('Keep the date'),
            self::PreviousBusinessDay => __('Move to the Friday before'),
            self::NextBusinessDay => __('Move to the Monday after'),
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
