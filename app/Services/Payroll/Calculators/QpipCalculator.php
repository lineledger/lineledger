<?php

namespace App\Services\Payroll\Calculators;

use App\Services\Payroll\Data\QpipResult;
use App\Support\Payroll\Constants\PayrollConstantSet;
use App\Support\Payroll\RoundingPolicy;

/**
 * Québec Parental Insurance Plan (QPIP / RQAP) premiums for one pay period.
 * Unlike EI, QPIP has DISTINCT employee and employer rates and its OWN maximum
 * insurable earnings ($98,000), independent of the EI MIE — so it needs its own
 * insurable year-to-date accumulator. The engine only calls this for Quebec.
 */
class QpipCalculator
{
    public function compute(
        PayrollConstantSet $c,
        int $insurableEarningsCents,
        int $ytdQpipEmployeeCents,
        int $ytdQpipInsurableCents,
        bool $isQpipExempt,
    ): QpipResult {
        if (! $c->isQuebec() || $isQpipExempt || $insurableEarningsCents <= 0) {
            return QpipResult::zero();
        }

        // Insurable earnings counted this period, capped at the $98,000 ceiling.
        $insurableUsed = max(0, min($insurableEarningsCents, $c->qpipMaxInsurableCents() - $ytdQpipInsurableCents));

        if ($insurableUsed <= 0) {
            return QpipResult::zero();
        }

        // Employee premium, capped at the annual maximum via year-to-date.
        $employeeUncapped = RoundingPolicy::centsTimesRate($insurableUsed, $c->qpipEmployeeRate());
        $maxEmployee = RoundingPolicy::centsTimesRate($c->qpipMaxInsurableCents(), $c->qpipEmployeeRate());
        $employee = min($employeeUncapped, max(0, $maxEmployee - $ytdQpipEmployeeCents));

        // Employer premium uses its own rate off the same insurable base.
        $employer = RoundingPolicy::centsTimesRate($insurableUsed, $c->qpipEmployerRate());

        return new QpipResult(
            qpipEmployeeCents: $employee,
            qpipEmployerCents: $employer,
            insurableUsedCents: $insurableUsed,
        );
    }
}
