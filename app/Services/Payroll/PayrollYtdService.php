<?php

namespace App\Services\Payroll;

use App\Enums\PayRunStatus;
use App\Models\EmployeePayrollProfile;
use App\Models\PayRunLine;
use App\Services\Payroll\Data\YtdTotals;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Derives an employee's year-to-date totals from POSTED pay-run lines earlier in
 * the same calendar year (keyed on the pay date, the CRA tax year). Voided runs
 * are excluded, so caps self-correct after a void — the same "posted rows are
 * the source of truth" discipline the GL balance caches use.
 */
class PayrollYtdService
{
    public function priorYtd(int $contactId, CarbonInterface $payDate, ?EmployeePayrollProfile $profile = null): YtdTotals
    {
        $row = PayRunLine::query()
            ->join('pay_runs', 'pay_runs.id', '=', 'pay_run_lines.pay_run_id')
            ->where('pay_run_lines.contact_id', $contactId)
            ->whereIn('pay_runs.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereYear('pay_runs.pay_date', $payDate->year)
            ->whereDate('pay_runs.pay_date', '<', $payDate->toDateString())
            ->selectRaw('
                COALESCE(SUM(pay_run_lines.cpp_pensionable_cents), 0) AS pensionable,
                COALESCE(SUM(pay_run_lines.ei_insurable_cents), 0) AS insurable,
                COALESCE(SUM(COALESCE(pay_run_lines.cpp_employee_override_cents, pay_run_lines.cpp_employee_computed_cents)), 0) AS cpp_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.cpp2_employee_override_cents, pay_run_lines.cpp2_employee_computed_cents)), 0) AS cpp2_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.ei_employee_override_cents, pay_run_lines.ei_employee_computed_cents)), 0) AS ei_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpp_employee_override_cents, pay_run_lines.qpp_employee_computed_cents)), 0) AS qpp_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpp2_employee_override_cents, pay_run_lines.qpp2_employee_computed_cents)), 0) AS qpp2_ee,
                COALESCE(SUM(COALESCE(pay_run_lines.qpip_employee_override_cents, pay_run_lines.qpip_employee_computed_cents)), 0) AS qpip_ee,
                COALESCE(SUM(pay_run_lines.qpip_insurable_cents), 0) AS qpip_insurable
            ')
            ->first();

        // Bonus-method income already paid this year (from the earning
        // snapshots of posted runs): positions a later bonus's annual-tax
        // delta in the correct bracket.
        $ytdBonus = (int) DB::table('pay_run_line_earnings as e')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'e.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereYear('pr.pay_date', $payDate->year)
            ->whereDate('pr.pay_date', '<', $payDate->toDateString())
            ->where('e.is_bonus_method', true)
            ->sum('e.amount_cents');

        // Mid-year opening balances seeded at onboarding count toward the caps,
        // but only for the tax year they belong to — a prior-year opening must not
        // inflate this year's accumulators.
        // Opening accumulators default to zero, and are added only when the profile
        // carries mid-year openings stamped within this tax year. Computed inside the
        // guard (where $profile is non-null) so no nullsafe access is needed.
        $openPensionable = $openInsurable = $openCpp = $openCpp2 = $openEi = 0;
        $openQpp = $openQpp2 = $openQpip = $openQpipInsurable = 0;

        if ($profile !== null
            && $profile->opening_balances_as_of !== null
            && $profile->opening_balances_as_of->year === $payDate->year) {
            $openPensionable = (int) $profile->opening_pensionable_cents;
            $openInsurable = (int) $profile->opening_insurable_cents;
            $openCpp = (int) $profile->opening_cpp_employee_cents;
            $openCpp2 = (int) $profile->opening_cpp2_employee_cents;
            $openEi = (int) $profile->opening_ei_employee_cents;
            $openQpp = (int) $profile->opening_qpp_employee_cents;
            $openQpp2 = (int) $profile->opening_qpp2_employee_cents;
            $openQpip = (int) $profile->opening_qpip_employee_cents;
            $openQpipInsurable = (int) $profile->opening_qpip_insurable_cents;
        }

        return new YtdTotals(
            pensionableCents: (int) ($row->pensionable ?? 0) + $openPensionable,
            insurableCents: (int) ($row->insurable ?? 0) + $openInsurable,
            cppEmployeeCents: (int) ($row->cpp_ee ?? 0) + $openCpp,
            cpp2EmployeeCents: (int) ($row->cpp2_ee ?? 0) + $openCpp2,
            eiEmployeeCents: (int) ($row->ei_ee ?? 0) + $openEi,
            qppEmployeeCents: (int) ($row->qpp_ee ?? 0) + $openQpp,
            qpp2EmployeeCents: (int) ($row->qpp2_ee ?? 0) + $openQpp2,
            qpipEmployeeCents: (int) ($row->qpip_ee ?? 0) + $openQpip,
            qpipInsurableCents: (int) ($row->qpip_insurable ?? 0) + $openQpipInsurable,
            bonusTaxableCents: $ytdBonus,
        );
    }
}
