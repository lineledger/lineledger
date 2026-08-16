<?php

namespace App\Support\Payroll;

/**
 * Centralizes the rounding rules CRA's T4127 payroll formulas use, so every
 * step's rounding mode lives in one place and is independently testable.
 *
 * All payroll money is integer cents. Statutory amounts (CPP, EI, tax) are
 * rounded to the nearest cent using half-up (round-half-away-from-zero), which
 * matches the CRA Payroll Deductions Online Calculator. bcmath is used for the
 * rate multiplications so large cent values never lose sub-cent precision to
 * floating point before the single, explicit rounding step.
 */
final class RoundingPolicy
{
    private const SCALE = 8;

    /**
     * Multiply an integer-cents amount by a decimal rate string and round the
     * product to the nearest whole cent (half-up).
     */
    public static function centsTimesRate(int $cents, string $rate): int
    {
        return self::roundBcToCents(bcmul((string) $cents, $rate, self::SCALE));
    }

    /**
     * Round a bcmath decimal-cents string to the nearest integer cent (half-up).
     */
    public static function roundBcToCents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $abs = $negative ? mb_substr($value, 1) : $value;

        // Add 0.5 then truncate toward zero = round half up.
        $rounded = bcadd($abs, '0.5', 0);

        return (int) ($negative ? '-'.$rounded : $rounded);
    }

    /**
     * Round an integer-cents amount to the nearest whole dollar, returned in
     * cents. Used where T4127 specifies dollar-level rounding.
     */
    public static function roundCentsToDollar(int $cents): int
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $dollars = intdiv($abs + 50, 100);

        return ($negative ? -$dollars : $dollars) * 100;
    }
}
