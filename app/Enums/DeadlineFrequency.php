<?php

namespace App\Enums;

/**
 * How often a deadline comes round. Every cadence but Once and AnnualDates
 * cuts time into back-to-back periods starting at the deadline's anchor; the
 * due date is then worked out from each period's end. AnnualDates is a fixed
 * list of month/day dates each year (employer health tax instalments), with no
 * period of its own.
 */
enum DeadlineFrequency: string
{
    case Once = 'once';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';
    case AnnualDates = 'annual_dates';

    public function label(): string
    {
        return match ($this) {
            self::Once => __('Once'),
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::SemiAnnual => __('Twice a year'),
            self::Annual => __('Yearly'),
            self::AnnualDates => __('Set dates each year'),
        };
    }

    /**
     * Months in one period, or null for week-based and dateless cadences.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
            self::Once, self::Weekly, self::AnnualDates => null,
        };
    }

    /**
     * Whether the cadence cuts time into periods (and so has a due rule).
     */
    public function hasPeriods(): bool
    {
        return $this !== self::Once && $this !== self::AnnualDates;
    }

    /**
     * Whether the "every N" multiplier applies.
     */
    public function takesInterval(): bool
    {
        return $this->hasPeriods();
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
