<?php

namespace App\Enums;

enum DataMigrationStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
        };
    }
}
