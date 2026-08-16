<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum PayFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case SemiMonthly = 'semi_monthly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('Weekly'),
            self::Biweekly => __('Biweekly'),
            self::SemiMonthly => __('Semi-monthly'),
            self::Monthly => __('Monthly'),
        };
    }

    /**
     * Number of pay periods in a calendar year. Drives the T4127 annualization
     * factor and CPP basic-exemption proration.
     */
    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Biweekly => 26,
            self::SemiMonthly => 24,
            self::Monthly => 12,
        };
    }

    /**
     * The period-end date immediately following the given period end.
     */
    public function nextPeriodEnd(CarbonInterface $periodEnd): CarbonImmutable
    {
        $date = CarbonImmutable::parse($periodEnd);

        return match ($this) {
            self::Weekly => $date->addWeek(),
            self::Biweekly => $date->addWeeks(2),
            self::SemiMonthly => $date->day <= 15 ? $date->endOfMonth()->startOfDay() : $date->addMonth()->setDay(15),
            self::Monthly => $date->addMonthNoOverflow()->endOfMonth()->startOfDay(),
        };
    }

    /**
     * The period-start date for a period ending on the given date — the inverse
     * of {@see nextPeriodEnd()}. Semi-monthly periods run 1st–15th and 16th–end
     * of month; monthly periods run the full calendar month.
     */
    public function periodStartFor(CarbonInterface $periodEnd): CarbonImmutable
    {
        $date = CarbonImmutable::parse($periodEnd);

        return match ($this) {
            self::Weekly => $date->subDays(6)->startOfDay(),
            self::Biweekly => $date->subDays(13)->startOfDay(),
            self::SemiMonthly => $date->day <= 15 ? $date->startOfMonth() : $date->setDay(16)->startOfDay(),
            self::Monthly => $date->startOfMonth(),
        };
    }
}
