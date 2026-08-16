<?php

namespace App\Enums;

/**
 * How a milestone payment request's amount is expressed: a percentage of the
 * invoice total, or a fixed cash amount. Either way the resolved cents are
 * stored on the row so the schedule is stable if the invoice total later moves.
 */
enum PaymentRequestType: string
{
    case Percent = 'percent';

    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percentage'),
            self::Fixed => __('Fixed amount'),
        };
    }
}
