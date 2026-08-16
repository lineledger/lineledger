<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Totals the source deductions and employer contributions a company must remit to
 * Revenu Québec (the TPZ-1015.R.14 statement) for a remitting period: Quebec
 * income tax withheld, employee + employer QPP/QPP2 and QPIP, and the employer
 * QHSF / CNESST levies, from POSTED pay runs whose pay date falls in the period.
 *
 * Reads the persisted EFFECTIVE amounts (override ?? computed) on Quebec lines.
 * Because the Quebec columns are 0 on rest-of-Canada lines, the period sums are
 * filter-free; only the per-line gross/insurable context is scoped to QC so the
 * "Quebec payroll" figure on the statement is right for mixed companies.
 */
class QuebecRemittanceCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array{
     *   quebec_tax_cents: int, qpp_ee_cents: int, qpp_er_cents: int, qpip_ee_cents: int,
     *   qpip_er_cents: int, qhsf_cents: int, cnesst_cents: int, total_qpp_cents: int,
     *   total_qpip_cents: int, quebec_gross_cents: int, employee_count: int, remittance_due_cents: int
     * }
     */
    public function summary(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = $this->lineQuery($company, $start, $end)
            ->selectRaw('
                COALESCE(SUM(COALESCE(prl.quebec_tax_override_cents, prl.quebec_tax_computed_cents)), 0) AS quebec_tax,
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)
                    + COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)), 0) AS qpp_ee,
                COALESCE(SUM(COALESCE(prl.qpp_employer_override_cents, prl.qpp_employer_computed_cents)
                    + COALESCE(prl.qpp2_employer_override_cents, prl.qpp2_employer_computed_cents)), 0) AS qpp_er,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)), 0) AS qpip_ee,
                COALESCE(SUM(COALESCE(prl.qpip_employer_override_cents, prl.qpip_employer_computed_cents)), 0) AS qpip_er,
                COALESCE(SUM(prl.qhsf_employer_computed_cents), 0) AS qhsf,
                COALESCE(SUM(prl.cnesst_employer_computed_cents), 0) AS cnesst,
                COALESCE(SUM(prl.gross_cents), 0) AS gross,
                COUNT(DISTINCT prl.contact_id) AS employees
            ')
            ->first();

        $quebecTax = (int) ($totals->quebec_tax ?? 0);
        $qppEe = (int) ($totals->qpp_ee ?? 0);
        $qppEr = (int) ($totals->qpp_er ?? 0);
        $qpipEe = (int) ($totals->qpip_ee ?? 0);
        $qpipEr = (int) ($totals->qpip_er ?? 0);
        $qhsf = (int) ($totals->qhsf ?? 0);
        $cnesst = (int) ($totals->cnesst ?? 0);

        $totalQpp = $qppEe + $qppEr;
        $totalQpip = $qpipEe + $qpipEr;

        return [
            'quebec_tax_cents' => $quebecTax,
            'qpp_ee_cents' => $qppEe,
            'qpp_er_cents' => $qppEr,
            'qpip_ee_cents' => $qpipEe,
            'qpip_er_cents' => $qpipEr,
            'qhsf_cents' => $qhsf,
            'cnesst_cents' => $cnesst,
            'total_qpp_cents' => $totalQpp,
            'total_qpip_cents' => $totalQpip,
            'quebec_gross_cents' => (int) ($totals->gross ?? 0),
            'employee_count' => (int) ($totals->employees ?? 0),
            // QHSF and CNESST go to Revenu Québec on the same statement.
            'remittance_due_cents' => $quebecTax + $totalQpp + $totalQpip + $qhsf + $cnesst,
        ];
    }

    /**
     * Per-pay-run breakdown rows for the detail table / CSV (Quebec lines only).
     *
     * @return array<int, array{run_no: string, pay_date: string, employees: int, quebec_gross_cents: int, qpp_cents: int, qpip_cents: int, quebec_tax_cents: int, levies_cents: int, remittance_cents: int}>
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
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)
                    + COALESCE(prl.qpp_employer_override_cents, prl.qpp_employer_computed_cents)
                    + COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)
                    + COALESCE(prl.qpp2_employer_override_cents, prl.qpp2_employer_computed_cents)), 0) AS qpp,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)
                    + COALESCE(prl.qpip_employer_override_cents, prl.qpip_employer_computed_cents)), 0) AS qpip,
                COALESCE(SUM(COALESCE(prl.quebec_tax_override_cents, prl.quebec_tax_computed_cents)), 0) AS quebec_tax,
                COALESCE(SUM(prl.qhsf_employer_computed_cents + prl.cnesst_employer_computed_cents), 0) AS levies
            ')
            ->get()
            ->map(fn ($r) => [
                'run_no' => (string) $r->run_no,
                'pay_date' => (string) $r->pay_date,
                'employees' => (int) $r->employees,
                'quebec_gross_cents' => (int) $r->gross,
                'qpp_cents' => (int) $r->qpp,
                'qpip_cents' => (int) $r->qpip,
                'quebec_tax_cents' => (int) $r->quebec_tax,
                'levies_cents' => (int) $r->levies,
                'remittance_cents' => (int) $r->qpp + (int) $r->qpip + (int) $r->quebec_tax + (int) $r->levies,
            ])
            ->all();
    }

    /**
     * Scopes to POSTED pay-run lines for Quebec employees in the period.
     */
    private function lineQuery(Company $company, CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereBetween('pr.pay_date', [$start->toDateString(), $end->toDateString()]);
    }
}
