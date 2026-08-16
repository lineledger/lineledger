<?php

namespace App\Enums;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnual => 'Semi-annual',
            self::Annual => 'Annual',
        };
    }

    /**
     * Number of months between occurrences, or null for week-based cadences.
     */
    public function monthsToAdd(): ?int
    {
        return match ($this) {
            self::Weekly => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
        };
    }

    public function isWeekly(): bool
    {
        return $this === self::Weekly;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $f) => ['value' => $f->value, 'label' => $f->label()],
            self::cases(),
        );
    }

    /**
     * Whether a day-of-month anchor applies (false for weekly cadences).
     */
    public function usesDayOfMonth(): bool
    {
        return ! $this->isWeekly();
    }
}
