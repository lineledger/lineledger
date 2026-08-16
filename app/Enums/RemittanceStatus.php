<?php

namespace App\Enums;

/**
 * Lifecycle of a recorded payroll remittance: Paid once the balanced journal
 * entry is posted, Void if it is later reversed.
 */
enum RemittanceStatus: string
{
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Paid => __('Paid'),
            self::Void => __('Void'),
        };
    }
}
