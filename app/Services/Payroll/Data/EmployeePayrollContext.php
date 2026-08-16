<?php

namespace App\Services\Payroll\Data;

use Carbon\CarbonInterface;

/**
 * The employee + pay-period parameters the deduction engine needs, independent
 * of any model so the engine stays pure and unit-testable.
 */
final readonly class EmployeePayrollContext
{
    public function __construct(
        public string $province,
        public int $payPeriodsPerYear,
        public CarbonInterface $payDate,
        public int $federalClaimCents,
        public int $provincialClaimCents,
        public bool $cppExempt = false,
        public bool $eiExempt = false,
        public int $additionalTaxPerPeriodCents = 0,
        public int $annualDeductionsCents = 0,
        public bool $qpipExempt = false,
        public bool $incomeTaxExempt = false,
        public ?CarbonInterface $dateOfBirth = null,
        public ?CarbonInterface $cpt30ElectionDate = null,
    ) {}
}
