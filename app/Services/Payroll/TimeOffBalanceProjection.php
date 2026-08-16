<?php

namespace App\Services\Payroll;

use App\Enums\PayRunStatus;
use App\Enums\TimeOffRequestStatus;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;

/**
 * What an employee's time-off balance looks like once in-flight requests are
 * counted. The posted balance only moves when a pay run posts, so an honest
 * preview must subtract:
 *   - open requests not yet confirmed (Pending / ManagerApproved), and
 *   - hours from Approved requests whose generated time entries haven't been
 *     consumed by a pay run yet (consumed ones are already in the balance).
 *
 * Shown live on the portal request form and the admin approval modal; an
 * insufficient projection is a WARNING, not a block (negative balances are
 * legal and representable).
 */
final class TimeOffBalanceProjection
{
    /**
     * @return array{current: float, pending: float, projected: float}
     */
    public function for(Contact $employee, TimeOffPolicy $policy, ?TimeOffRequest $excluding = null): array
    {
        $current = (float) (EmployeeAccrualBalance::query()
            ->whereHas('profile', fn ($q) => $q->where('contact_id', $employee->id))
            ->where('code', $policy->code)
            ->value('balance_hours') ?? 0);

        $open = TimeOffRequest::query()
            ->where('contact_id', $employee->id)
            ->where('time_off_policy_id', $policy->id)
            ->when($excluding?->id, fn ($q, int $id) => $q->where('id', '!=', $id))
            ->whereIn('status', [TimeOffRequestStatus::Pending->value, TimeOffRequestStatus::ManagerApproved->value])
            ->sum('total_hours');

        $approvedUnconsumed = TimeOffRequest::query()
            ->where('contact_id', $employee->id)
            ->where('time_off_policy_id', $policy->id)
            ->when($excluding?->id, fn ($q, int $id) => $q->where('id', '!=', $id))
            ->where('status', TimeOffRequestStatus::Approved->value)
            ->withSum(['timeEntries as unconsumed_hours' => fn ($q) => $q->where(fn ($e) => $e->whereNull('pay_run_id')
                // Pulled into a run that hasn't POSTED: the balance hasn't
                // moved yet, so these hours are still pending.
                ->orWhereHas('payRun', fn ($r) => $r->whereIn('status', [PayRunStatus::Draft->value, PayRunStatus::Calculated->value])))], 'hours')
            ->get()
            ->sum(fn (TimeOffRequest $r): float => (float) ($r->unconsumed_hours ?? 0));

        $pending = (float) $open + $approvedUnconsumed;

        return [
            'current' => $current,
            'pending' => $pending,
            'projected' => round($current - $pending, 2),
        ];
    }
}
