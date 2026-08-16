<?php

namespace App\Services\Payroll;

use App\Enums\PayRunStatus;
use App\Models\EmployeeAccrualBalance;
use App\Support\Payroll\BankedOvertimeRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Advisory aging of banked-overtime balances. Employment standards put a clock
 * on banked time (e.g. 90 days federally/ON, 180 in BC/AB, 365 in SK/QC); the
 * ledger only keeps running totals, so the earn history (posted pay-run
 * accrual rows) is replayed FIFO — used hours consume the oldest lots first —
 * and whatever remains in lots older than the province deadline is flagged.
 * Reporting only; nothing is auto-paid-out.
 */
final class BankedTimeAging
{
    /**
     * Employees whose banked balance contains hours past their province's
     * take-or-pay-out deadline.
     *
     * @return list<array{contact_id: int, name: string, balance_hours: float, overdue_hours: float, oldest_date: ?string, deadline_days: int}>
     */
    public function overdue(CarbonImmutable $asOf): array
    {
        $balances = EmployeeAccrualBalance::query()
            ->where('code', 'banked')
            ->where('balance_hours', '>', 0)
            ->with('profile.contact')
            ->get();

        $rows = [];

        foreach ($balances as $balance) {
            $profile = $balance->profile;

            if ($profile === null) {
                continue;
            }

            $deadlineDays = BankedOvertimeRules::forProvince((string) $profile->province_of_employment)['payout_deadline_days'];

            if ($deadlineDays === null) {
                continue;
            }

            // FIFO replay: earn lots oldest-first, minus everything ever used.
            $lots = DB::table('pay_run_line_accruals as a')
                ->join('pay_run_lines as prl', 'prl.id', '=', 'a.pay_run_line_id')
                ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
                ->where('prl.employee_payroll_profile_id', $profile->id)
                ->whereIn('pr.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
                ->where('a.code', 'banked')
                ->where('a.hours', '>', 0)
                ->orderBy('pr.pay_date')
                ->get(['a.hours', 'pr.pay_date']);

            $used = (float) $balance->used_ytd_hours;
            $overdue = 0.0;
            $oldestDate = null;

            foreach ($lots as $lot) {
                $remaining = (float) $lot->hours - $used;
                $used = max(0.0, $used - (float) $lot->hours);

                if ($remaining <= 0) {
                    continue;
                }

                $oldestDate ??= substr((string) $lot->pay_date, 0, 10);

                if (CarbonImmutable::parse((string) $lot->pay_date)->addDays($deadlineDays)->lt($asOf)) {
                    $overdue += $remaining;
                }
            }

            if ($overdue <= 0) {
                continue;
            }

            $rows[] = [
                'contact_id' => (int) $profile->contact_id,
                'name' => $profile->contact->display_name,
                'balance_hours' => (float) $balance->balance_hours,
                'overdue_hours' => round($overdue, 2),
                'oldest_date' => $oldestDate,
                'deadline_days' => $deadlineDays,
            ];
        }

        return $rows;
    }
}
