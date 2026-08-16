<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
