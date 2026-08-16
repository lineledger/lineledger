<?php

namespace App\Enums;

enum PayrollChequeStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
