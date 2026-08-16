<?php

namespace App\Enums;

use App\Models\Member;

/**
 * Derived lifecycle of a membership. Never persisted — computed from the member's
 * term dates and cancellation on {@see Member::effectiveStatus()}.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Lapsed => 'Lapsed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Flux badge colour for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Lapsed => 'amber',
            self::Expired => 'red',
            self::Cancelled => 'zinc',
        };
    }
}
