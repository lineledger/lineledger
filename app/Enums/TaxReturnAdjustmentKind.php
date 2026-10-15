<?php

namespace App\Enums;

/**
 * The box on a tax return a manual adjustment changes.
 *
 * Collected and Paid adjust the tax collected and the input tax credits; their
 * amount adds to that box. Other is anything else on the return — a collector's
 * commission, a bank fee, a correction carried from an earlier period — and its
 * amount is signed by its effect on the net owing (a commission is negative).
 */
enum TaxReturnAdjustmentKind: string
{
    case Collected = 'collected';
    case Paid = 'paid';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Collected => 'Collected',
            self::Paid => 'ITC',
            self::Other => 'Other',
        };
    }

    /**
     * The change an adjustment of this kind makes to the net owing.
     */
    public function effectOnNet(int $amountCents): int
    {
        return $this === self::Paid ? -$amountCents : $amountCents;
    }
}
