<?php

namespace App\Exceptions\Payroll;

use RuntimeException;

/**
 * Thrown when no payroll tax table is loaded for a requested pay date or
 * province. The pay-run UI catches this and blocks the run with a clear message
 * rather than silently computing with stale or missing constants.
 */
class MissingPayrollConstantsException extends RuntimeException
{
    public static function forDate(string $payDate): self
    {
        return new self("No federal payroll tax table is loaded for {$payDate}. Load the CRA T4127 constants for that period before running payroll.");
    }

    public static function forProvince(string $province, string $payDate): self
    {
        return new self("No payroll tax table is loaded for province {$province} effective {$payDate}. Load that province's CRA T4127 constants before running payroll.");
    }
}
