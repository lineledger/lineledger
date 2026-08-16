<?php

namespace App\Services\Payroll\Data;

/**
 * The authoritative per-employee, per-pay-period deduction result. Every
 * component is integer cents. Voluntary deductions (RRSP, benefits, garnishments)
 * are itemized upstream; this carries only their total for the net-pay maths.
 *
 * Quebec employees populate the QPP/QPIP/Quebec-tax fields and leave the
 * CPP/provincial-tax fields at 0; the rest of Canada is the reverse. The EI and
 * federal-tax fields apply to both. Because the inapplicable side is always 0,
 * the totals below are branch-free.
 */
final readonly class PayrollDeductionResult
{
    public function __construct(
        public int $grossCents,
        public int $taxablePensionableUsedCents,
        public int $insurableUsedCents,
        public int $cppEmployeeCents,
        public int $cppEmployerCents,
        public int $cpp2EmployeeCents,
        public int $cpp2EmployerCents,
        public int $eiEmployeeCents,
        public int $eiEmployerCents,
        public int $federalTaxCents,
        public int $provincialTaxCents,
        public int $additionalTaxCents = 0,
        public int $voluntaryDeductionsCents = 0,
        // Quebec (0 for the rest of Canada):
        public int $qppEmployeeCents = 0,
        public int $qppEmployerCents = 0,
        public int $qpp2EmployeeCents = 0,
        public int $qpp2EmployerCents = 0,
        public int $qpipEmployeeCents = 0,
        public int $qpipEmployerCents = 0,
        public int $qpipInsurableUsedCents = 0,
        public int $quebecTaxCents = 0,
    ) {}

    public function totalIncomeTaxCents(): int
    {
        return $this->federalTaxCents + $this->provincialTaxCents + $this->quebecTaxCents + $this->additionalTaxCents;
    }

    /**
     * Statutory + voluntary deductions withheld from the employee.
     */
    public function totalEmployeeDeductionsCents(): int
    {
        return $this->cppEmployeeCents
            + $this->cpp2EmployeeCents
            + $this->eiEmployeeCents
            + $this->qppEmployeeCents
            + $this->qpp2EmployeeCents
            + $this->qpipEmployeeCents
            + $this->totalIncomeTaxCents()
            + $this->voluntaryDeductionsCents;
    }

    public function netPayCents(): int
    {
        return $this->grossCents - $this->totalEmployeeDeductionsCents();
    }

    /**
     * Total employer cost on top of gross pay (employer CPP/CPP2/EI + QPP/QPP2/QPIP).
     */
    public function employerContributionsCents(): int
    {
        return $this->cppEmployerCents + $this->cpp2EmployerCents + $this->eiEmployerCents
            + $this->qppEmployerCents + $this->qpp2EmployerCents + $this->qpipEmployerCents;
    }
}
