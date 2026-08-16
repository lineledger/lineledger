<?php

namespace App\Enums;

enum CostingMethod: string
{
    case WeightedAverage = 'weighted_average';
    case Fifo = 'fifo';

    public function label(): string
    {
        return match ($this) {
            self::WeightedAverage => 'Weighted Average',
            self::Fifo => 'FIFO',
        };
    }
}
