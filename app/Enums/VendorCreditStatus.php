<?php

namespace App\Enums;

enum VendorCreditStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Posted;
    }
}
