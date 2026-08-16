<?php

namespace App\Enums;

enum AssetStatus: string
{
    case InService = 'in-service';
    case Disposed = 'disposed';
    case Sold = 'sold';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::InService => 'In service',
            self::Disposed => 'Disposed',
            self::Sold => 'Sold',
            self::Lost => 'Lost',
        };
    }

    public function isRetired(): bool
    {
        return $this !== self::InService;
    }
}
