<?php

namespace App\Enums;

/**
 * The kind of time-off a policy grants. Drives the default label and the
 * statement section; the accrual mechanics come from the policy's method/unit.
 */
enum TimeOffCategory: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Personal = 'personal';
    case Bereavement = 'bereavement';
    case Banked = 'banked';
    case Other = 'other';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => __('Vacation'),
            self::Sick => __('Sick'),
            self::Personal => __('Personal'),
            self::Bereavement => __('Bereavement'),
            self::Banked => __('Banked time'),
            self::Other => __('Other'),
            self::Unpaid => __('Unpaid'),
        };
    }

    /** Flux badge/chip color, shared by the staff + portal calendars. */
    public function color(): string
    {
        return match ($this) {
            self::Vacation => 'sky',
            self::Sick => 'rose',
            self::Personal => 'violet',
            self::Bereavement => 'zinc',
            self::Banked => 'indigo',
            self::Other => 'cyan',
            self::Unpaid => 'amber',
        };
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
