<?php

namespace App\Services\Reporting;

use App\Enums\PayFrequency;
use App\Enums\PayRunStatus;
use App\Enums\RoeReason;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Record of Employment (ROE) data a user transcribes into Service
 * Canada's ROE Web form when an employee leaves. Reads stored insurable hours
 * and earnings per pay period from POSTED pay runs (electronic XML submission is
 * out of scope — the user files on the Service Canada site).
 */
class RoeCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * Number of recent consecutive pay periods Block 15C requires, by frequency.
     */
    private const PERIODS_BY_FREQUENCY = [
        'weekly' => 53,
        'biweekly' => 27,
        'semi_monthly' => 25,
        'monthly' => 13,
    ];

    /**
     * @return array{
     *   employee: string, sin_last4: ?string, first_day: ?string, last_day: ?string,
     *   final_period_end: ?string, reason: string, reason_label: string,
     *   total_insurable_hours: string, total_insurable_earnings_cents: int,
     *   periods: array<int, array{period_end: string, insurable_hours: string, insurable_earnings_cents: int}>
     * }
     */
    public function build(Company $company, Contact $employee, RoeReason $reason, string $lastDay): array
    {
        $employee->loadMissing('payrollProfile.payrollSchedule');
        $profile = $employee->payrollProfile;

        $frequency = $profile?->payrollSchedule?->frequency;
        $maxPeriods = $frequency instanceof PayFrequency
            ? (self::PERIODS_BY_FREQUENCY[$frequency->value] ?? 27)
            : 27;

        $periods = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.contact_id', $employee->id)
            ->whereIn('pr.status', self::POSTED)
            ->orderByDesc('pr.period_end_date')
            ->orderByDesc('pr.id')
            ->limit($maxPeriods)
            ->get([
                'pr.period_end_date as period_end',
                'prl.insurable_hours as insurable_hours',
                'prl.ei_insurable_cents as insurable_earnings_cents',
            ]);

        $rows = $periods->map(fn ($p) => [
            'period_end' => (string) $p->period_end,
            'insurable_hours' => (string) (float) $p->insurable_hours,
            'insurable_earnings_cents' => (int) $p->insurable_earnings_cents,
        ])->all();

        $totalHours = array_sum(array_map(fn ($r) => (float) $r['insurable_hours'], $rows));
        $totalEarnings = array_sum(array_column($rows, 'insurable_earnings_cents'));

        return [
            'employee' => (string) $employee->display_name,
            'sin_last4' => $profile?->sin_last4,
            'first_day' => $profile?->hire_date?->toDateString(),
            'last_day' => $lastDay,
            'final_period_end' => $rows[0]['period_end'] ?? null,
            'reason' => $reason->value,
            'reason_label' => $reason->label(),
            'total_insurable_hours' => (string) $totalHours,
            'total_insurable_earnings_cents' => $totalEarnings,
            'periods' => $rows,
        ];
    }
}
