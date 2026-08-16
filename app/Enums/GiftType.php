<?php

namespace App\Enums;

/**
 * Whether a donation is cash or a gift-in-kind. Cash receipts are record-only
 * (the money is booked elsewhere); in-kind receipts post the gift to the GL at
 * fair market value.
 */
enum GiftType: string
{
    case Cash = 'cash';
    case InKind = 'in_kind';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::InKind => 'Gift in kind',
        };
    }
}
