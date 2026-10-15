<?php

namespace App\Enums;

/**
 * How the company lock date treats a posting dated on or before it — the two
 * "close the books" behaviours QuickBooks offers.
 */
enum PeriodLockMode: string
{
    /** Refuse the posting; Owners and Admins may override it with their password. */
    case Block = 'block';

    /** Ask the user to confirm, then let the posting through. */
    case Warn = 'warn';

    public function label(): string
    {
        return match ($this) {
            self::Block => __('Block'),
            self::Warn => __('Warn and confirm'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Block => __('Refuse changes on or before the lock date. Owners and Admins can override one transaction at a time with their password.'),
            self::Warn => __('Show a warning and let the user continue after confirming.'),
        };
    }
}
