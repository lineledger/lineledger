<?php

namespace App\Enums;

enum TaxReturnPaymentDirection: string
{
    case Outgoing = 'outgoing'; // company pays the tax agency
    case Incoming = 'incoming'; // company receives a refund from the tax agency

    public function label(): string
    {
        return $this === self::Outgoing ? 'Payment to agency' : 'Refund from agency';
    }
}
