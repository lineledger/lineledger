<?php

namespace App\Enums;

/**
 * How an occurrence stands today: its stored status plus where its due date
 * sits relative to the organization's today. Worked out on read, never stored.
 */
enum DeadlineState: string
{
    case Overdue = 'overdue';
    case DueToday = 'due_today';
    case DueSoon = 'due_soon';
    case Upcoming = 'upcoming';
    case Done = 'done';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Overdue => __('Overdue'),
            self::DueToday => __('Due today'),
            self::DueSoon => __('Due soon'),
            self::Upcoming => __('Upcoming'),
            self::Done => __('Done'),
            self::Skipped => __('Not required'),
        };
    }

    /**
     * The Flux badge colour for this state.
     */
    public function color(): string
    {
        return match ($this) {
            self::Overdue => 'red',
            self::DueToday, self::DueSoon => 'amber',
            self::Upcoming => 'zinc',
            self::Done => 'green',
            self::Skipped => 'zinc',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done && $this !== self::Skipped;
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
