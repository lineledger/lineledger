<?php

namespace App\Enums;

/**
 * The stored status of one occurrence of a deadline.
 */
enum DeadlineStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Done => __('Done'),
            self::Skipped => __('Not required'),
        };
    }
}
