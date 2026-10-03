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
            self::Standard => __('Standard'),
            self::AddingMachine => __('Adding machine'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standard => __('Works like a normal calculator: enter a number, pick an operator, then = for the result. Every step prints to the tape.'),
            self::AddingMachine => __('Works like an accountant\'s 10-key: + and − add or subtract each entry to a running total; press Total for the grand total.'),
        };
    }
}
