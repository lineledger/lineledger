<?php

namespace App\Enums;

enum StockAdjustmentReason: string
{
    case OpeningBalance = 'opening_balance';
    case Shrinkage = 'shrinkage';
    case Damage = 'damage';
    case Recount = 'recount';
    case WriteOff = 'write_off';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Shrinkage => 'Shrinkage',
            self::Damage => 'Damage',
            self::Recount => 'Recount',
            self::WriteOff => 'Write-off',
            self::Other => 'Other',
        };
    }
}
