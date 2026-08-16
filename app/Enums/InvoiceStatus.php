<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Posted, self::Partial]);
    }
}
