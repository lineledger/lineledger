<?php

namespace App\Enums;

/**
 * Broad flavour of a daily insight. The selector uses it for anti-repeat
 * windows and tie-breaking; the history page uses it for the row badge.
 */
enum InsightCategory: string
{
    case Deadline = 'deadline';
    case Hygiene = 'hygiene';
    case Fact = 'fact';

    /**
     * How many days the same detector key is suppressed after being shown.
     * Deadlines legitimately re-surface as the date nears; facts are mostly
     * monthly by nature, so a long window keeps the card varied.
     */
    public function antiRepeatDays(): int
    {
        return match ($this) {
            self::Deadline => 4,
            self::Hygiene => 10,
            self::Fact => 21,
        };
    }

    /** Tie-break weight when two candidates score equal: action beats trivia. */
    public function priority(): int
    {
        return match ($this) {
            self::Deadline => 3,
            self::Hygiene => 2,
            self::Fact => 1,
        };
    }

    /** Short label for the history-page badge. */
    public function label(): string
    {
        return match ($this) {
            self::Deadline => __('Deadline'),
            self::Hygiene => __('Heads-up'),
            self::Fact => __('Did you know'),
        };
    }
}
