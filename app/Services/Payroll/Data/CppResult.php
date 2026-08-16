<?php

namespace App\Services\Payroll\Data;

/**
 * CPP / CPP2 contributions for one pay period (all integer cents).
 *
 * The base/enhanced split matters for income tax: the base portion (4.95%)
 * yields a non-refundable credit, while the enhanced portion (the 1% first
 * additional plus all of CPP2) is deductible from taxable income.
 */
final readonly class CppResult
{
    public function __construct(
        public int $cppEmployeeCents,
        public int $cppEmployerCents,
        public int $cpp2EmployeeCents,
        public int $cpp2EmployerCents,
        public int $baseCppEmployeeCents,
        public int $enhancedDeductibleCents,
        public int $pensionableUsedCents,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0);
    }
}
