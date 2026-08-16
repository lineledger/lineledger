<?php

namespace App\Services\Payroll\Calculators;

use App\Services\Payroll\Data\CppResult;
use App\Support\Payroll\Constants\PayrollConstantSet;
use App\Support\Payroll\RoundingPolicy;

/**
 * Canada Pension Plan (base + enhanced) and CPP2 (second additional) for one
 * pay period, following CRA's T4127. Annual maximums are enforced using the
 * year-to-date amounts the caller supplies, so the final period of the year is
 * capped correctly without an annual recompute.
 */
class CppCalculator
{
    public function compute(
        PayrollConstantSet $c,
        int $pensionableEarningsCents,
        int $payPeriodsPerYear,
        int $ytdPensionableCents,
        int $ytdCppEmployeeCents,
        int $ytdCpp2EmployeeCents,
        bool $isCppExempt,
    ): CppResult {
        if ($isCppExempt || $pensionableEarningsCents <= 0 || $payPeriodsPerYear <= 0) {
            return CppResult::zero();
        }

        // Per-period basic exemption, kept at full precision until the final cent.
        $exemptionPerPeriod = bcdiv((string) $c->cppBasicExemptionCents(), (string) $payPeriodsPerYear, 8);
        $contributory = bcsub((string) $pensionableEarningsCents, $exemptionPerPeriod, 8);

        if (bccomp($contributory, '0', 8) <= 0) {
            return new CppResult(0, 0, 0, 0, 0, 0, 0);
        }

        // Base CPP (4.95% + 1% enhanced = 5.95%), capped by the annual maximum.
        $cppUncapped = RoundingPolicy::roundBcToCents(bcmul($contributory, $c->cppRate(), 8));
        $cppRoom = max(0, $c->cppMaxContributionCents() - $ytdCppEmployeeCents);
        $cppEmployee = min($cppUncapped, $cppRoom);

        // Split the capped contribution into its base and enhanced portions by
        // ratio, so base + enhanced always reconcile to the capped amount.
        $baseCpp = $cppEmployee > 0
            ? RoundingPolicy::roundBcToCents(bcmul((string) $cppEmployee, bcdiv($c->cppBaseRate(), $c->cppRate(), 10), 10))
            : 0;
        $firstAdditional = $cppEmployee - $baseCpp;

        // CPP2: applies to pensionable earnings in the (YMPE, YAMPE] band.
        $cpp2Base = max(0, min($ytdPensionableCents + $pensionableEarningsCents, $c->yampeCents()) - max($ytdPensionableCents, $c->cpp2LowerCents()));
        $cpp2Uncapped = RoundingPolicy::centsTimesRate($cpp2Base, $c->cpp2Rate());
        $cpp2Room = max(0, $c->cpp2MaxContributionCents() - $ytdCpp2EmployeeCents);
        $cpp2Employee = min($cpp2Uncapped, $cpp2Room);

        // Pensionable earnings counted this period for T4 box 26 (capped at YAMPE).
        $pensionableUsed = max(0, min($pensionableEarningsCents, $c->yampeCents() - $ytdPensionableCents));

        return new CppResult(
            cppEmployeeCents: $cppEmployee,
            cppEmployerCents: $cppEmployee,
            cpp2EmployeeCents: $cpp2Employee,
            cpp2EmployerCents: $cpp2Employee,
            baseCppEmployeeCents: $baseCpp,
            enhancedDeductibleCents: $firstAdditional + $cpp2Employee,
            pensionableUsedCents: $pensionableUsed,
        );
    }
}
