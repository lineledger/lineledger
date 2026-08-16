<?php

namespace App\Enums;

enum BankReconciliationStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
        };
    }
}
