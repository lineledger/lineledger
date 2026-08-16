<?php

namespace App\Enums;

enum RecurringDocumentType: string
{
    case Invoice = 'invoice';
    case Bill = 'bill';

    public function label(): string
    {
        return $this === self::Invoice ? 'Invoice' : 'Bill';
    }
}
