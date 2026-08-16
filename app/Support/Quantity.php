<?php

namespace App\Support;

final class Quantity
{
    /**
     * Format a stored decimal quantity for a form input: strip trailing zeros
     * (and a bare trailing dot) while preserving a genuine zero.
     * "1.0000" → "1", "2.5000" → "2.5", "0.0000" → "0".
     */
    public static function format(int|float|string|null $value): string
    {
        $trimmed = rtrim(rtrim((string) $value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
