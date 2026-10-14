<?php

namespace App\Enums;

/**
 * How money is paid back to a customer: a cheque (drafted for review, then
 * printed and posted), or a card refund through the payment terminal (a
 * negative customer receipt, posted at once).
 */
enum RefundMethod: string
{
    case Cheque = 'cheque';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cheque => __('By cheque'),
            self::Card => __('By credit card'),
        };
    }
}
