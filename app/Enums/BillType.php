<?php

namespace App\Enums;

enum BillType: string
{
    case Vendor = 'vendor';
    case Reimbursement = 'reimbursement';

    public function label(): string
    {
        return $this === self::Vendor ? 'Bill' : 'Reimbursement';
    }
}
