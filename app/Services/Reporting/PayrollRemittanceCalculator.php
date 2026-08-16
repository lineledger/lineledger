<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Totals the source deductions a company must remit to the CRA (the PD7A) for a
 * remitting period: income tax withheld plus employee + employer CPP/CPP2 and
 * EI, from POSTED pay runs whose pay date falls in the period. Reads the
 * persisted EFFECTIVE amounts (override ?? computed) so the remittance equals
 * what was actually deducted — including manual adjustments.
 */
class PayrollRemittanceCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array{
     *   tax_cents: int, cpp_ee_cents: int, cpp_er_cents: int, cpp2_ee_cents: int, cpp2_er_cents: int,
     *   ei_ee_cents: int, ei_er_cents: int, gross_payroll_cents: int, employee_count: int,
     *   last_period_employee_count: int, total_cpp_cents: int, total_ei_cents: int, remittance_due_cents: int
     * }
     */
    public function summary(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = $this->lineQuery($company, $start, $end)
            ->selectRaw('
                COALESCE(SUM(COALESCE(prl.federal_tax_override_cents, prl.federal_tax_computed_cents)
                    + COALESCE(prl.provincial_tax_override_cents, prl.provincial_tax_computed_cents)
                    + COALESCE(prl.additional_tax_override_cents, prl.additional_tax_computed_cents)), 0) AS tax,
                COALESCE(SUM(COALESCE(prl.cpp_employee_override_cents, prl.cpp_employee_computed_cents)), 0) AS cpp_ee,
                COALESCE(SUM(COALESCE(prl.cpp_employer_override_cents, prl.cpp_employer_computed_cents)), 0) AS cpp_er,
                COALESCE(SUM(COALESCE(prl.cpp2_employee_override_cents, prl.cpp2_employee_computed_cents)), 0) AS cpp2_ee,
                COALESCE(SUM(COALESCE(prl.cpp2_employer_override_cents, prl.cpp2_employer_computed_cents)), 0) AS cpp2_er,
                COALESCE(SUM(COALESCE(prl.ei_employee_override_cents, prl.ei_employee_computed_cents)), 0) AS ei_ee,
                COALESCE(SUM(COALESCE(prl.ei_employer_override_cents, prl.ei_employer_computed_cents)), 0) AS ei_er,
                COALESCE(SUM(prl.gross_cents), 0) AS gross,
                COUNT(DISTINCT prl.contact_id) AS employees
            ')
            ->first();

        $tax = (int) ($totals->tax ?? 0);
        $cppEe = (int) ($totals->cpp_ee ?? 0);
        $cppEr = (int) ($totals->cpp_er ?? 0);
        $cpp2Ee = (int) ($totals->cpp2_ee ?? 0);
        $cpp2Er = (int) ($totals->cpp2_er ?? 0);
        $eiEe = (int) ($totals->ei_ee ?? 0);
        $eiEr = (int) ($totals->ei_er ?? 0);

        $totalCpp = $cppEe + $cppEr + $cpp2Ee + $cpp2Er;
        $totalEi = $eiEe + $eiEr;

        return [
            'tax_cents' => $tax,
            'cpp_ee_cents' => $cppEe,
            'cpp_er_cents' => $cppEr,
            'cpp2_ee_cents' => $cpp2Ee,
            'cpp2_er_cents' => $cpp2Er,
            'ei_ee_cents' => $eiEe,
            'ei_er_cents' => $eiEr,
            'gross_payroll_cents' => (int) ($totals->gross ?? 0),
            'employee_count' => (int) ($totals->employees ?? 0),
            'last_period_employee_count' => $this->lastPeriodEmployeeCount($company, $start, $end),
            'total_cpp_cents' => $totalCpp,
            'total_ei_cents' => $totalEi,
            'remittance_due_cents' => $totalCpp + $totalEi + $tax,
        ];
    }

    /**
     * Per-pay-run breakdown rows for the detail table / CSV.
     *
     * @return array<int, array{run_no: string, pay_date: string, employees: int, gross_cents: int, cpp_cents: int, ei_cents: int, tax_cents: int, remittance_cents: int}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->lineQuery($company, $start, $end)
            ->groupBy('pr.id', 'pr.run_no', 'pr.pay_date')
            ->orderBy('pr.pay_date')
            ->selectRaw('
                pr.run_no AS run_no,
                pr.pay_date AS pay_date,
                COUNT(DISTINCT prl.contact_id) AS employees,
                COALESCE(SUM(prl.gross_cents), 0) AS gross,
                COALESCE(SUM(COALESCE(prl.cpp_employee_override_cents, prl.cpp_employee_computed_cents)
                    + COALESCE(prl.cpp_employer_override_cents, prl.cpp_employer_computed_cents)
                    + COALESCE(prl.cpp2_employee_override_cents, prl.cpp2_employee_computed_cents)
                    + COALESCE(prl.cpp2_employer_override_cents, prl.cpp2_employer_computed_cents)), 0) AS cpp,
                COALESCE(SUM(COALESCE(prl.ei_employee_override_cents, prl.ei_employee_computed_cents)
                    + COALESCE(prl.ei_employer_override_cents, prl.ei_employer_computed_cents)), 0) AS ei,
                COALESCE(SUM(COALESCE(prl.federal_tax_override_cents, prl.federal_tax_computed_cents)
                    + COALESCE(prl.provincial_tax_override_cents, prl.provincial_tax_computed_cents)
                    + COALESCE(prl.additional_tax_override_cents, prl.additional_tax_computed_cents)), 0) AS tax
            ')
            ->get()
            ->map(fn ($r) => [
                'run_no' => (string) $r->run_no,
                'pay_date' => (string) $r->pay_date,
                'employees' => (int) $r->employees,
                'gross_cents' => (int) $r->gross,
                'cpp_cents' => (int) $r->cpp,
                'ei_cents' => (int) $r->ei,
                'tax_cents' => (int) $r->tax,
                'remittance_cents' => (int) $r->cpp + (int) $r->ei + (int) $r->tax,
            ])
            ->all();
    }

    private function lineQuery(Company $company, CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->whereIn('pr.status', self::POSTED)
            ->whereBetween('pr.pay_date', [$start->toDateString(), $end->toDateString()]);
    }

    private function lastPeriodEmployeeCount(Company $company, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $lastRunId = DB::table('pay_runs')
            ->where('company_id', $company->id)
            ->whereIn('status', self::POSTED)
            ->whereBetween('pay_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('pay_date')
            ->orderByDesc('id')
            ->value('id');

        if ($lastRunId === null) {
            return 0;
        }

        return (int) DB::table('pay_run_lines')
            ->where('pay_run_id', $lastRunId)
            ->distinct()
            ->count('contact_id');
    }
}
