<?php

namespace App\Enums;

/**
 * A Sales Receipt is a pay-now sale: there is no Accounts-Receivable lifecycle,
 * so unlike an invoice it has no partial/paid states — just draft, posted, void.
 */
enum SalesReceiptStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
