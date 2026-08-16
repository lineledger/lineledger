<?php

namespace App\Services\Payroll\Data;

use App\Services\Payroll\EarningsAggregator;

/**
 * The pay-period earnings resolved into the bases each statutory deduction uses
 * (all integer cents). Produced by {@see EarningsAggregator}
 * from the per-earning pensionable/insurable/taxable flags so the calculators
 * never special-case earning types.
 *
 * - taxableCents is the GROSS taxable earnings for the period; pre-tax
 *   deductions are NOT netted into it.
 * - deductionsPerPeriodCents is the T4127 "F" factor (RRSP/union dues), which
 *   the income-tax calculator subtracts from income exactly once.
 */
final readonly class EarningsBreakdown
{
    public function __construct(
        public int $grossCents,
        public int $pensionableCents,
        public int $insurableCents,
        public int $taxableCents,
        public int $deductionsPerPeriodCents = 0,
        // QPIP's insurable base can diverge from EI's per item flag; null
        // mirrors the EI base (the pre-split behaviour).
        public ?int $qpipInsurableCents = null,
        // The slice of taxableCents taxed by the T4127 bonus/retro method.
        public int $bonusTaxableCents = 0,
    ) {}
}
