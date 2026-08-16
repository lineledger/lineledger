<?php

namespace App\Services\Payroll\Data;

/**
 * Income tax withheld for one pay period (all integer cents), split federal /
 * provincial / Quebec. For a Quebec employee the federal amount is already
 * abated, provincialTaxCents is 0, and the Quebec tax (Revenu Québec) lives in
 * quebecTaxCents; for the rest of Canada quebecTaxCents is 0. The annual figures
 * are exposed for auditing and tests.
 */
final readonly class IncomeTaxResult
{
    public function __construct(
        public int $federalTaxCents,
        public int $provincialTaxCents,
        public int $annualizedIncomeCents,
        public int $federalAnnualTaxCents,
        public int $provincialAnnualTaxCents,
        public int $quebecTaxCents = 0,
        public int $quebecAnnualTaxCents = 0,
    ) {}
}
