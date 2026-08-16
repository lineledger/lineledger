<?php

namespace App\Enums;

enum PayBasis: string
{
    case Salary = 'salary';
    case Hourly = 'hourly';
    case Commission = 'commission';

    public function label(): string
    {
        return match ($this) {
            self::Salary => __('Salary'),
            self::Hourly => __('Hourly'),
            self::Commission => __('Commission'),
        };
    }
}
