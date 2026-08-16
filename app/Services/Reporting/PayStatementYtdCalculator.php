<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\PayRunLine;
use App\Models\PayRunLineContribution;
use App\Models\PayRunLineDeduction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the per-code and total year-to-date figures a pay statement shows
 * alongside each current-period amount, for one {@see PayRunLine}.
 *
 * YTD is summed from POSTED pay-run lines for the same employee in the pay date's
 * calendar year, EXCLUDING the line's own run, with the current line added back
 * exactly once. That way the YTD is right whether the statement is printed after
 * posting (the run is in the posted set) or previewed while still Calculated (the
 * run isn't) — and it never double-counts when two runs share a pay date. Reads
 * stored actuals only (effective = override ?? computed), mirroring
 * {@see PayrollYtdService} and {@see T4SlipCalculator}.
 */
class PayStatementYtdCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array{
     *   earnings: array<string, array{name: string, current_cents: int, ytd_cents: int, add_to_bases_only: bool, add_to_net_pay_only: bool}>,
     *   deductions: array<string, array{name: string, current_cents: int, ytd_cents: int}>,
     *   benefits: array<string, array{name: string, current_cents: int, ytd_cents: int}>,
     *   accruals: array<string, array{name: string, current_cents: int, ytd_cents: int, current_hours: float, ytd_hours: float}>,
     *   statutory: array<string, array{current: int, ytd: int}>,
     *   gross_current: int, gross_ytd: int, deductions_current: int, deductions_ytd: int,
     *   net_current: int, net_ytd: int, hours_current: float, hours_ytd: float
     * }
     */
    public function forLine(PayRunLine $line): array
    {
        $line->loadMissing('payRun', 'earnings', 'deductions', 'contributions', 'accruals');

        $contactId = (int) $line->contact_id;
        $payDate = CarbonImmutable::parse($line->payRun->pay_date);
        $year = $payDate->year;
        $runId = (int) $line->pay_run_id;

        // Prior posted line aggregates for the year (this run excluded).
        $prior = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->where('pr.id', '!=', $runId)
            ->selectRaw('
                COALESCE(SUM(prl.gross_cents), 0) AS gross,
                COALESCE(SUM(prl.total_deductions_cents), 0) AS deductions,
                COALESCE(SUM(prl.net_cents), 0) AS net,
                COALESCE(SUM(prl.hours_worked), 0) AS hours,
                COALESCE(SUM(COALESCE(prl.cpp_employee_override_cents, prl.cpp_employee_computed_cents)), 0) AS cpp_ee,
                COALESCE(SUM(COALESCE(prl.cpp2_employee_override_cents, prl.cpp2_employee_computed_cents)), 0) AS cpp2_ee,
                COALESCE(SUM(COALESCE(prl.ei_employee_override_cents, prl.ei_employee_computed_cents)), 0) AS ei_ee,
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)), 0) AS qpp_ee,
                COALESCE(SUM(COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)), 0) AS qpp2_ee,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)), 0) AS qpip_ee,
                COALESCE(SUM(
                    COALESCE(prl.federal_tax_override_cents, prl.federal_tax_computed_cents)
                    + COALESCE(prl.provincial_tax_override_cents, prl.provincial_tax_computed_cents)
                    + COALESCE(prl.quebec_tax_override_cents, prl.quebec_tax_computed_cents)
                    + COALESCE(prl.additional_tax_override_cents, prl.additional_tax_computed_cents)
                ), 0) AS income_tax
            ')
            ->first();

        $statutory = [
            'cpp_employee' => $this->stat($line->cppEmployeeCents(), (int) $prior->cpp_ee),
            'cpp2_employee' => $this->stat($line->cpp2EmployeeCents(), (int) $prior->cpp2_ee),
            'ei_employee' => $this->stat($line->eiEmployeeCents(), (int) $prior->ei_ee),
            'qpp_employee' => $this->stat($line->qppEmployeeCents(), (int) $prior->qpp_ee),
            'qpp2_employee' => $this->stat($line->qpp2EmployeeCents(), (int) $prior->qpp2_ee),
            'qpip_employee' => $this->stat($line->qpipEmployeeCents(), (int) $prior->qpip_ee),
            'income_tax' => $this->stat($line->incomeTaxCents(), (int) $prior->income_tax),
        ];

        // Map the concrete child rows to plain {code, name, amount_cents} shapes
        // (so the per-code aggregator stays model-agnostic).
        $toShape = static fn (PayRunLineDeduction|PayRunLineContribution $r): array => [
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'amount_cents' => (int) $r->amount_cents,
        ];

        return [
            'earnings' => $this->earningsByCode($line, $contactId, $year, $runId),
            'deductions' => $this->childByCode('pay_run_line_deductions', $line->deductions->map($toShape)->all(), $contactId, $year, $runId),
            'benefits' => $this->childByCode('pay_run_line_contributions', $line->contributions->map($toShape)->all(), $contactId, $year, $runId),
            'accruals' => $this->accrualsByCode($line, $contactId, $year, $runId),
            'statutory' => $statutory,
            'gross_current' => (int) $line->gross_cents,
            'gross_ytd' => (int) $prior->gross + (int) $line->gross_cents,
            'deductions_current' => (int) $line->total_deductions_cents,
            'deductions_ytd' => (int) $prior->deductions + (int) $line->total_deductions_cents,
            'net_current' => (int) $line->net_cents,
            'net_ytd' => (int) $prior->net + (int) $line->net_cents,
            'hours_current' => (float) $line->hours_worked,
            'hours_ytd' => (float) $prior->hours + (float) $line->hours_worked,
        ];
    }

    /** @return array{current: int, ytd: int} */
    private function stat(int $current, int $prior): array
    {
        return ['current' => $current, 'ytd' => $prior + $current];
    }

    /**
     * Per-code current + YTD for the earning rows, carrying the cash/non-cash
     * flags so the statement can route bases-only benefits to the right section.
     *
     * @return array<string, array{name: string, current_cents: int, ytd_cents: int, add_to_bases_only: bool, add_to_net_pay_only: bool}>
     */
    private function earningsByCode(PayRunLine $line, int $contactId, int $year, int $runId): array
    {
        $priorByCode = $this->priorByCode('pay_run_line_earnings', $contactId, $year, $runId);

        $rows = [];

        foreach ($line->earnings as $earning) {
            $code = (string) $earning->code;
            $rows[$code] ??= [
                'name' => (string) $earning->name,
                'current_cents' => 0,
                'ytd_cents' => (int) ($priorByCode[$code] ?? 0),
                'add_to_bases_only' => (bool) $earning->add_to_bases_only,
                'add_to_net_pay_only' => (bool) $earning->add_to_net_pay_only,
            ];
            $rows[$code]['current_cents'] += (int) $earning->amount_cents;
            $rows[$code]['ytd_cents'] += (int) $earning->amount_cents;
        }

        return $rows;
    }

    /**
     * Per-code current + YTD for a simple cents child table (deductions/contributions).
     *
     * @param  array<int, array{code: string, name: string, amount_cents: int}>  $currentRows
     * @return array<string, array{name: string, current_cents: int, ytd_cents: int}>
     */
    private function childByCode(string $table, array $currentRows, int $contactId, int $year, int $runId): array
    {
        $priorByCode = $this->priorByCode($table, $contactId, $year, $runId);

        $rows = [];

        foreach ($currentRows as $row) {
            $code = $row['code'];
            $rows[$code] ??= [
                'name' => $row['name'],
                'current_cents' => 0,
                'ytd_cents' => (int) ($priorByCode[$code] ?? 0),
            ];
            $rows[$code]['current_cents'] += $row['amount_cents'];
            $rows[$code]['ytd_cents'] += $row['amount_cents'];
        }

        return $rows;
    }

    /**
     * Per-code current + YTD for accruals, in both dollars and hours.
     *
     * @return array<string, array{name: string, current_cents: int, ytd_cents: int, current_hours: float, ytd_hours: float}>
     */
    private function accrualsByCode(PayRunLine $line, int $contactId, int $year, int $runId): array
    {
        $prior = DB::table('pay_run_line_accruals as x')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'x.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->where('pr.id', '!=', $runId)
            ->groupBy('x.code')
            ->selectRaw('x.code AS code, COALESCE(SUM(x.amount_cents),0) AS cents, COALESCE(SUM(x.hours),0) AS hours')
            ->get()
            ->keyBy('code');

        $rows = [];

        foreach ($line->accruals as $accrual) {
            $code = (string) $accrual->code;
            $rows[$code] ??= [
                'name' => (string) $accrual->name,
                'current_cents' => 0,
                'ytd_cents' => (int) ($prior[$code]->cents ?? 0),
                'current_hours' => 0.0,
                'ytd_hours' => (float) ($prior[$code]->hours ?? 0),
            ];
            $rows[$code]['current_cents'] += (int) $accrual->amount_cents;
            $rows[$code]['ytd_cents'] += (int) $accrual->amount_cents;
            $rows[$code]['current_hours'] += (float) $accrual->hours;
            $rows[$code]['ytd_hours'] += (float) $accrual->hours;
        }

        return $rows;
    }

    /**
     * Sum of a child table's posted amounts by code for the year, this run excluded.
     *
     * @return array<string, int>
     */
    private function priorByCode(string $table, int $contactId, int $year, int $runId): array
    {
        return DB::table($table.' as x')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'x.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->where('pr.id', '!=', $runId)
            ->groupBy('x.code')
            ->selectRaw('x.code AS code, COALESCE(SUM(x.amount_cents),0) AS amount')
            ->pluck('amount', 'code')
            ->map(fn ($a) => (int) $a)
            ->all();
    }
}
