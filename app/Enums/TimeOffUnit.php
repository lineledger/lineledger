<?php

namespace App\Enums;

/**
 * The unit a time-off policy is measured in: hours (sick/personal) or dollars
 * (a percent-of-earnings policy, like vacation pay).
 */
enum TimeOffUnit: string
{
    case Hours = 'hours';
    case Dollars = 'dollars';

    public function label(): string
    {
        return match ($this) {
            self::Hours => __('Hours'),
            self::Dollars => __('Dollars'),
        };
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
