<?php

namespace App\Enums;

/**
 * Where a journal line on a tax agency's payable account belongs.
 *
 * Only Collected and Paid make up a tax return. The other three are real
 * movements on the payable account that a return must not count — they show
 * up only in the return's reconciliation to the ledger:
 *  - Payment: a remittance to (or refund from) the agency;
 *  - Adjustment: an entry a filed return posted (commission, write-off, …);
 *  - Opening: the opening-balance journal entry.
 */
enum SalesTaxBucket: string
{
    case Collected = 'collected';
    case Paid = 'paid';
    case Payment = 'payment';
    case Adjustment = 'adjustment';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Collected => 'Collected',
            self::Paid => 'Paid (ITC)',
            self::Payment => 'Payment',
            self::Adjustment => 'Return adjustment',
            self::Opening => 'Opening balance',
        };
    }

    /**
     * Whether a line in this bucket is part of a tax return's figures, rather
     * than only its reconciliation.
     */
    public function isOnReturn(): bool
    {
        return $this === self::Collected || $this === self::Paid;
    }
}
