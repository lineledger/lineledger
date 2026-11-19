<?php

namespace App\Enums;

/**
 * How often a member gets the summary email of their deadlines.
 */
enum DeadlineDigestFrequency: string
{
    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Daily => __('Every morning'),
            self::Weekly => __('Monday mornings'),
        };
    }

    /**
     * Days ahead the digest looks.
     */
    public function lookaheadDays(): int
    {
        return match ($this) {
            self::Daily => 7,
            self::Weekly, self::Off => 14,
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
