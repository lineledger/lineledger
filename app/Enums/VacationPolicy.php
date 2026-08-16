<?php

namespace App\Enums;

enum VacationPolicy: string
{
    /** Accrue vacation pay to a liability each run; pay it out later. */
    case Accrue = 'accrue';

    /** Pay vacation pay on every cheque as an additional earning. */
    case PayEachCheque = 'pay_each_cheque';

    public function label(): string
    {
        return match ($this) {
            self::Accrue => __('Accrue to liability'),
            self::PayEachCheque => __('Pay on every cheque'),
        };
    }
}
