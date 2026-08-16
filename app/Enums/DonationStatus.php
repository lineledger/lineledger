<?php

namespace App\Enums;

/**
 * Lifecycle of a recorded donation. A draft books no GL; posting writes the
 * cash-in entry; voiding reverses it.
 */
enum DonationStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Void => 'Void',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Posted => 'green',
            self::Void => 'red',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }
}
