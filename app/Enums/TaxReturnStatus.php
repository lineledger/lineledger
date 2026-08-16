<?php

namespace App\Enums;

enum TaxReturnStatus: string
{
    case Draft = 'draft';
    case Filed = 'filed';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return $this === self::Filed;
    }
}
