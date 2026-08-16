<?php

namespace App\Services\Payroll\Verification;

use App\Enums\PayFrequency;
use App\Services\Payroll\Data\YtdTotals;

/**
 * One payroll-calculation reference case: a set of inputs plus the EXPECTED
 * statutory amounts. A null expected value means "no reference loaded yet" — run
 * the case through CRA's Payroll Deductions Online Calculator (PDOC) and paste
 * the number to lock it.
 *
 * The `source` records how the expected value was obtained:
 *   - 'pdoc':    copied from CRA PDOC (independently verifies constants + engine)
 *   - 'formula': hand-derived from the T4127 formula (catches engine regressions
 *                against the intended math, but not constant transcription errors)
 *   - 'awaiting': expected not yet supplied
 */
final readonly class PayrollCheck
{
    public function __construct(
        public string $id,
        public string $label,
        public string $province,
        public PayFrequency $frequency,
        public int $grossPerPeriodCents,
        public int $federalClaimCents,
        public int $provincialClaimCents,
        public YtdTotals $ytd,
        public ?int $expectedCppEmployeeCents,
        public ?int $expectedCpp2EmployeeCents,
        public ?int $expectedEiEmployeeCents,
        public ?int $expectedFederalTaxCents,
        public ?int $expectedProvincialTaxCents,
        public string $source,
        public string $payDate = '2025-06-15',
        public string $notes = '',
        // Quebec components (null for the rest of Canada). QPP/QPIP/EI-QC are
        // formula-confident; Quebec income tax stays null until WebRAS-confirmed.
        public ?int $expectedQppEmployeeCents = null,
        public ?int $expectedQpipEmployeeCents = null,
        public ?int $expectedQuebecTaxCents = null,
    ) {}
}
