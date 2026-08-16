<?php

namespace App\Services\Payroll;

use App\Enums\TimeOffAccrualMethod;
use App\Models\Company;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeeTimeOffPolicy;
use Carbon\CarbonImmutable;

/**
 * Applies the non-per-run side of time-off accrual: at each policy's cycle
 * boundary (calendar-year start for beginning-of-year / per-period / per-hour
 * policies; the hire-date anniversary for anniversary policies) it
 *   1. carries over at most `carryover_max` and forfeits the excess,
 *   2. resets the YTD accrued / used counters, and
 *   3. for beginning-of-year / anniversary policies, grants the annual lump
 *      (`rate_hours`, capped at `annual_cap_hours`).
 *
 * Idempotent per cycle via the assignment's `last_accrued_on`. Per-period and
 * per-hour policies accrue on each pay run (see {@see CalculatePayRun}); here they
 * only get the year-boundary carryover + reset. Manual policies are never touched.
 */
class TimeOffAccrualService
{
    /**
     * Process every active assignment for a company as of a date. Returns the
     * number of assignments advanced to a new cycle.
     */
    public function accrueForCompany(Company $company, CarbonImmutable $asOf): int
    {
        $assignments = EmployeeTimeOffPolicy::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->with(['policy', 'profile'])
            ->get();

        $advanced = 0;

        foreach ($assignments as $assignment) {
            if ($this->process($assignment, (int) $company->id, $asOf)) {
                $advanced++;
            }
        }

        return $advanced;
    }

    private function process(EmployeeTimeOffPolicy $assignment, int $companyId, CarbonImmutable $asOf): bool
    {
        $policy = $assignment->policy;
        $profile = $assignment->profile;

        if ($policy === null || ! $policy->is_active || $profile === null) {
            return false;
        }

        $boundary = $this->cycleBoundary($policy->accrual_method, $profile->hire_date, $asOf);

        if ($boundary === null || $asOf->lt($boundary)) {
            return false;
        }

        // Idempotency: already advanced to this cycle (or later).
        $last = $assignment->last_accrued_on !== null ? CarbonImmutable::parse($assignment->last_accrued_on) : null;

        if ($last !== null && $last->gte($boundary)) {
            return false;
        }

        // FIRST processing of a mid-cycle assignment is enrollment, not a year
        // boundary: clamping would truncate a freshly-seeded opening balance to
        // carryover_max and resetting would wipe live YTD counters. Lump-grant
        // policies still hand out the year's allotment — unless the employer
        // stated an opening balance, which IS the remaining entitlement.
        $isEnrollment = $last === null;

        $balance = EmployeeAccrualBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_payroll_profile_id', $assignment->employee_payroll_profile_id)
            ->where('code', $policy->code)
            ->first()
            ?? new EmployeeAccrualBalance([
                'company_id' => $companyId,
                'employee_payroll_profile_id' => $assignment->employee_payroll_profile_id,
                'code' => $policy->code,
            ]);

        $balance->name = $policy->name;

        if ($policy->isDollarUnit()) {
            if (! $isEnrollment) {
                if ($policy->carryover_max_cents !== null) {
                    $balance->balance_cents = min((int) $balance->balance_cents, (int) $policy->carryover_max_cents);
                }
                $balance->accrued_ytd_cents = 0;
                $balance->used_ytd_cents = 0;
            }
        } else {
            if (! $isEnrollment) {
                if ($policy->carryover_max_hours !== null) {
                    $balance->balance_hours = min((float) $balance->balance_hours, (float) $policy->carryover_max_hours);
                }
                $balance->accrued_ytd_hours = 0;
                $balance->used_ytd_hours = 0;
            }

            // Lump grant for beginning-of-year / anniversary policies.
            $opening = (float) ($assignment->opening_balance_hours ?? 0);

            if ($policy->accrual_method->isLumpGrant() && ! ($isEnrollment && $opening > 0)) {
                $grant = (float) ($assignment->rate_override_hours ?? $policy->rate_hours);

                if ($policy->annual_cap_hours !== null) {
                    $grant = min($grant, (float) $policy->annual_cap_hours);
                }

                $balance->balance_hours = (float) $balance->balance_hours + $grant;
                $balance->accrued_ytd_hours = (float) $balance->accrued_ytd_hours + $grant;
            }
        }

        $balance->save();

        $assignment->forceFill(['last_accrued_on' => $boundary->toDateString()])->save();

        return true;
    }

    /**
     * The start of the cycle that `asOf` falls in, or null when the method has no
     * command-driven cycle (manual) or the anniversary hasn't arrived this year.
     */
    private function cycleBoundary(TimeOffAccrualMethod $method, mixed $hireDate, CarbonImmutable $asOf): ?CarbonImmutable
    {
        if ($method === TimeOffAccrualMethod::Manual) {
            return null;
        }

        if ($method === TimeOffAccrualMethod::Anniversary) {
            if ($hireDate === null) {
                return null;
            }

            $anniversary = CarbonImmutable::parse($hireDate)->setYear($asOf->year)->startOfDay();

            return $asOf->gte($anniversary) ? $anniversary : null;
        }

        // Beginning-of-year, per-pay-period, per-hour-worked → calendar-year start.
        return CarbonImmutable::create($asOf->year, 1, 1, 0, 0, 0, $asOf->timezone);
    }
}
