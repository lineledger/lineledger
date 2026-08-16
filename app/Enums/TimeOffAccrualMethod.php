<?php

namespace App\Enums;

/**
 * How a time-off policy accrues. Per-period and per-hour-worked accrue on each
 * pay run (through the engine); beginning-of-year and anniversary grant an annual
 * lump via the `payroll:accrue-time-off` command; manual never auto-accrues.
 */
enum TimeOffAccrualMethod: string
{
    case PerPayPeriod = 'per_pay_period';
    case PerHourWorked = 'per_hour_worked';
    case BeginningOfYear = 'beginning_of_year';
    case Anniversary = 'anniversary';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::PerPayPeriod => __('Per pay period'),
            self::PerHourWorked => __('Per hour worked'),
            self::BeginningOfYear => __('Beginning of year'),
            self::Anniversary => __('On work anniversary'),
            self::Manual => __('Manual only'),
        };
    }

    /** Whether this method accrues on each pay run (vs a lump grant by command). */
    public function accruesPerRun(): bool
    {
        return $this === self::PerPayPeriod || $this === self::PerHourWorked;
    }

    /** Whether this method is granted as an annual lump by the AccrueTimeOff command. */
    public function isLumpGrant(): bool
    {
        return $this === self::BeginningOfYear || $this === self::Anniversary;
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
