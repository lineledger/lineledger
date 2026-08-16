<?php

namespace App\Enums;

enum CalculatorMode: string
{
    case Standard = 'standard';
    case AddingMachine = 'adding_machine';

    public static function default(): self
    {
        return self::Standard;
    }

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::AddingMachine => 'Adding machine',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standard => 'Works like a normal calculator: enter a number, pick an operator, then = for the result. Every step prints to the tape.',
            self::AddingMachine => 'Works like an accountant\'s 10-key: + and − add or subtract each entry to a running total; press Total for the grand total.',
        };
    }
}
