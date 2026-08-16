<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Totals the workers'-comp (WSIB/WCB) levy a company owes its provincial board for
 * a remitting period — the sum of `wc_employer_computed_cents` on POSTED pay-run
 * lines whose pay date falls in the period. Quebec lines carry 0 (CNESST), so the
 * period sum is naturally rest-of-Canada only. Sibling of
 * {@see PayrollRemittanceCalculator}.
 */
class WorkersCompRemittanceCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array{wc_cents: int, gross_assessable_cents: int, employee_count: int, remittance_due_cents: int}
     */
    public function summary(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = $this->lineQuery($company, $start, $end)
            ->selectRaw('
                COALESCE(SUM(prl.wc_employer_computed_cents), 0) AS wc,
                COALESCE(SUM(CASE WHEN prl.wc_employer_computed_cents > 0 THEN prl.gross_cents ELSE 0 END), 0) AS assessable,
                COUNT(DISTINCT CASE WHEN prl.wc_employer_computed_cents > 0 THEN prl.contact_id END) AS employees
            ')
            ->first();

        $wc = (int) ($totals->wc ?? 0);

        return [
            'wc_cents' => $wc,
            'gross_assessable_cents' => (int) ($totals->assessable ?? 0),
            'employee_count' => (int) ($totals->employees ?? 0),
            'remittance_due_cents' => $wc,
        ];
    }

    /**
     * Per-pay-run breakdown rows for the detail table / CSV.
     *
     * @return array<int, array{run_no: string, pay_date: string, employees: int, assessable_cents: int, wc_cents: int, remittance_cents: int}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->lineQuery($company, $start, $end)
            ->groupBy('pr.id', 'pr.run_no', 'pr.pay_date')
            ->orderBy('pr.pay_date')
            ->selectRaw('
                pr.run_no AS run_no,
                pr.pay_date AS pay_date,
                COUNT(DISTINCT CASE WHEN prl.wc_employer_computed_cents > 0 THEN prl.contact_id END) AS employees,
                COALESCE(SUM(CASE WHEN prl.wc_employer_computed_cents > 0 THEN prl.gross_cents ELSE 0 END), 0) AS assessable,
                COALESCE(SUM(prl.wc_employer_computed_cents), 0) AS wc
            ')
            ->get()
            ->map(fn ($r) => [
                'run_no' => (string) $r->run_no,
                'pay_date' => (string) $r->pay_date,
                'employees' => (int) $r->employees,
                'assessable_cents' => (int) $r->assessable,
                'wc_cents' => (int) $r->wc,
                'remittance_cents' => (int) $r->wc,
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
}
