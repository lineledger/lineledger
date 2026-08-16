<?php

namespace App\Enums;

/**
 * Lifecycle of a milestone payment request. Requested and Cancelled are stored
 * (user-controlled); Paid is derived at read time from the invoice's cumulative
 * payments, so the schedule never drifts out of step with the single AR balance.
 */
enum PaymentRequestStatus: string
{
    case Requested = 'requested';

    case Paid = 'paid';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Requested'),
            self::Paid => __('Paid'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
