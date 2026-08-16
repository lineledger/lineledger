<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\PayrollDeductionResult;
use App\Services\Payroll\Data\QpipResult;
use App\Services\Payroll\Data\YtdTotals;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use Carbon\CarbonImmutable;

/**
 * The single entry point for Canadian source-deduction calculation. Given an
 * employee context, the pay-period earnings breakdown, and the employee's
 * year-to-date totals, returns every CPP/EI/tax component for the period.
 *
 * For Quebec the same CppCalculator/EiCalculator produce QPP and Quebec-rate EI
 * (the constant set returns Quebec rates), QpipCalculator adds QPIP, and the
 * pension/tax results are mapped into the QPP and Quebec-tax fields while the
 * CPP and provincial-tax fields stay 0 — so downstream sums are filter-free.
 *
 * Pure and deterministic — no database access — so it can be exhaustively
 * unit-tested against CRA's PDOC and Revenu Québec's WebRAS.
 */
class PayrollDeductionEngine
{
    public function __construct(
        private PayrollConstantsRepository $constants,
        private CppCalculator $cpp,
        private EiCalculator $ei,
        private IncomeTaxCalculator $tax,
        private QpipCalculator $qpip,
    ) {}

    public function compute(
        EmployeePayrollContext $context,
        EarningsBreakdown $earnings,
        YtdTotals $ytd,
        int $voluntaryDeductionsCents = 0,
    ): PayrollDeductionResult {
        $c = $this->constants->resolve($context->province, $context->payDate);
        $isQc = $c->isQuebec();

        // Pension: one call. For QC the constant set returns QPP rates and the
        // YTD comes from the QPP accumulators (cpp_* stays 0 for QC lines).
        $pension = $this->cpp->compute(
            $c,
            $earnings->pensionableCents,
            $context->payPeriodsPerYear,
            $ytd->pensionableCents,
            $isQc ? $ytd->qppEmployeeCents : $ytd->cppEmployeeCents,
            $isQc ? $ytd->qpp2EmployeeCents : $ytd->cpp2EmployeeCents,
            $context->cppExempt || $this->pensionAgeExempt($context, $isQc),
        );

        // EI: one call; the rate is the Quebec reduced rate for QC.
        $ei = $this->ei->compute(
            $c,
            $earnings->insurableCents,
            $ytd->eiEmployeeCents,
            $ytd->insurableCents,
            $context->eiExempt,
        );

        // QPIP: Quebec only. Its insurable base may diverge from EI's per item
        // flag (null mirrors EI, the pre-split behaviour).
        $qpip = $isQc
            ? $this->qpip->compute($c, $earnings->qpipInsurableCents ?? $earnings->insurableCents, $ytd->qpipEmployeeCents, $ytd->qpipInsurableCents, $context->qpipExempt)
            : QpipResult::zero();

        // T4127 bonus method: bonus/retro lumps are NOT annualized as period
        // income. Regular tax is computed on the period income WITHOUT the
        // lump; the lump's withholding is the annual-tax delta it causes on
        // top of (annualized regular income + bonuses already paid this year).
        // The deductible enhanced CPP is apportioned between the two shares
        // (T4127's F5A/F5B split).
        $bonus = min(max(0, $earnings->bonusTaxableCents), $earnings->taxableCents);
        $regularTaxable = $earnings->taxableCents - $bonus;
        $enhancedBonus = $earnings->taxableCents > 0
            ? (int) round($pension->enhancedDeductibleCents * $bonus / $earnings->taxableCents)
            : 0;
        $enhancedRegular = $pension->enhancedDeductibleCents - $enhancedBonus;

        $taxArgs = fn (int $annualLump) => $this->tax->compute(
            $c,
            $regularTaxable,
            $context->payPeriodsPerYear,
            $earnings->deductionsPerPeriodCents,
            $enhancedRegular,
            $context->annualDeductionsCents,
            $context->federalClaimCents,
            $context->provincialClaimCents,
            $pension->baseCppEmployeeCents,
            $ei->eiEmployeeCents,
            $qpip->qpipEmployeeCents,
            $context->incomeTaxExempt,
            $annualLump,
        );

        $tax = $taxArgs(0);

        $bonusFederal = 0;
        $bonusProvincial = 0;
        $bonusQuebec = 0;

        if ($bonus > 0 && ! $context->incomeTaxExempt) {
            $with = $taxArgs(max(0, $ytd->bonusTaxableCents) + $bonus - $enhancedBonus);
            $without = $taxArgs(max(0, $ytd->bonusTaxableCents));

            $bonusFederal = max(0, $with->federalAnnualTaxCents - $without->federalAnnualTaxCents);
            $bonusProvincial = max(0, $with->provincialAnnualTaxCents - $without->provincialAnnualTaxCents);
            $bonusQuebec = max(0, $with->quebecAnnualTaxCents - $without->quebecAnnualTaxCents);
        }

        return new PayrollDeductionResult(
            grossCents: $earnings->grossCents,
            taxablePensionableUsedCents: $pension->pensionableUsedCents,
            insurableUsedCents: $ei->insurableUsedCents,
            // CPP fields carry the pension for ROC; QPP fields for Quebec.
            cppEmployeeCents: $isQc ? 0 : $pension->cppEmployeeCents,
            cppEmployerCents: $isQc ? 0 : $pension->cppEmployerCents,
            cpp2EmployeeCents: $isQc ? 0 : $pension->cpp2EmployeeCents,
            cpp2EmployerCents: $isQc ? 0 : $pension->cpp2EmployerCents,
            eiEmployeeCents: $ei->eiEmployeeCents,
            eiEmployerCents: $ei->eiEmployerCents,
            federalTaxCents: $tax->federalTaxCents + $bonusFederal,
            provincialTaxCents: $tax->provincialTaxCents + $bonusProvincial,
            additionalTaxCents: $context->additionalTaxPerPeriodCents,
            voluntaryDeductionsCents: $voluntaryDeductionsCents,
            qppEmployeeCents: $isQc ? $pension->cppEmployeeCents : 0,
            qppEmployerCents: $isQc ? $pension->cppEmployerCents : 0,
            qpp2EmployeeCents: $isQc ? $pension->cpp2EmployeeCents : 0,
            qpp2EmployerCents: $isQc ? $pension->cpp2EmployerCents : 0,
            qpipEmployeeCents: $qpip->qpipEmployeeCents,
            qpipEmployerCents: $qpip->qpipEmployerCents,
            qpipInsurableUsedCents: $qpip->insurableUsedCents,
            quebecTaxCents: $tax->quebecTaxCents + $bonusQuebec,
        );
    }

    /**
     * CRA T4127 age rules for CPP/QPP contributions:
     *  - neither plan applies before the month AFTER the employee turns 18;
     *  - CPP stops the month after the employee turns 70, and a CPT30 election
     *    (ages 65–70) stops it the first of the month after it is filed;
     *  - QPP has no upper-age stop and no CPT30 equivalent, so for Quebec only
     *    the under-18 boundary applies.
     *
     * v1 limitation: the on/off boundary is applied per pay date; the annual
     * basic exemption and maximum are not month-prorated for the transition year
     * (a small, safe simplification, mirroring the factor-S note in the tax
     * calculator).
     */
    private function pensionAgeExempt(EmployeePayrollContext $context, bool $isQuebec): bool
    {
        $payDate = CarbonImmutable::parse($context->payDate);

        if ($context->dateOfBirth !== null) {
            $dob = CarbonImmutable::parse($context->dateOfBirth);

            // Contributions begin the month after the 18th birthday.
            if ($payDate->lt($dob->addYears(18)->addMonthNoOverflow()->startOfMonth())) {
                return true;
            }

            // CPP (not QPP) stops the month after the 70th birthday.
            if (! $isQuebec && $payDate->gte($dob->addYears(70)->addMonthNoOverflow()->startOfMonth())) {
                return true;
            }
        }

        // CPT30 election to stop CPP (ROC only), effective the first of the month
        // after it is filed.
        if (! $isQuebec && $context->cpt30ElectionDate !== null) {
            $effective = CarbonImmutable::parse($context->cpt30ElectionDate)->addMonthNoOverflow()->startOfMonth();

            if ($payDate->gte($effective)) {
                return true;
            }
        }

        return false;
    }
}
