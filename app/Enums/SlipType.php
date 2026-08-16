<?php

namespace App\Enums;

use App\Models\PayrollSlipFiling;

/**
 * The kind of Canadian year-end slip a {@see PayrollSlipFiling}
 * locks: CRA T4 (employment income), Revenu Québec RL-1, or CRA T4A
 * (fees for services). Each maps to its matching slip calculator.
 */
enum SlipType: string
{
    case T4 = 't4';
    case Rl1 = 'rl1';
    case T4a = 't4a';

    public function label(): string
    {
        return match ($this) {
            self::T4 => __('T4'),
            self::Rl1 => __('RL-1'),
            self::T4a => __('T4A'),
        };
    }
}
