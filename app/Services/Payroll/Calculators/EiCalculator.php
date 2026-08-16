<?php

namespace App\Services\Payroll\Calculators;

use App\Services\Payroll\Data\EiResult;
use App\Support\Payroll\Constants\PayrollConstantSet;
use App\Support\Payroll\RoundingPolicy;

/**
 * Employment Insurance premiums for one pay period. The rate comes from the
 * constant set, so Quebec automatically uses its reduced EI rate (QPIP is a
 * separate premium — see QpipCalculator). The annual maximum premium is enforced
 * via year-to-date, and the employer premium is 1.4× the (capped) employee premium.
 */
class EiCalculator
{
    public function compute(
        PayrollConstantSet $c,
        int $insurableEarningsCents,
        int $ytdEiEmployeeCents,
        int $ytdInsurableCents,
        bool $isEiExempt,
    ): EiResult {
        if ($isEiExempt || $insurableEarningsCents <= 0) {
            return EiResult::zero();
        }

        $uncapped = RoundingPolicy::centsTimesRate($insurableEarningsCents, $c->eiRate());
        $room = max(0, $c->eiMaxPremiumCents() - $ytdEiEmployeeCents);
        $employee = min($uncapped, $room);

        $employer = RoundingPolicy::centsTimesRate($employee, $c->eiEmployerFactor());

        $insurableUsed = max(0, min($insurableEarningsCents, $c->eiMaxInsurableCents() - $ytdInsurableCents));

        return new EiResult(
            eiEmployeeCents: $employee,
            eiEmployerCents: $employer,
            insurableUsedCents: $insurableUsed,
        );
    }
}
