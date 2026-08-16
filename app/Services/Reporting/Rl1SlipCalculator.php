<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a calendar year of POSTED Quebec pay-run lines into Relevé 1 (RL-1)
 * slips, one per Quebec employee, mapping the persisted effective amounts to the
 * Revenu Québec RL-1 boxes. Reads stored actuals only — never re-runs the engine.
 *
 * Box map:
 *   A  Employment income          → sum of gross
 *   B  QPP contribution           → qpp_employee (effective)
 *   E  Quebec income tax withheld → quebec_tax (effective)
 *   G  QPP pensionable salary     → cpp_pensionable (the QPP base; reused column)
 *   H  QPIP premium               → qpip_employee (effective)
 *   I  QPIP insurable salary      → qpip_insurable
 *
 * The RL-1 Summary additionally surfaces the WSDRF (1% workforce skills training)
 * reconciliation when the company is subject to it: 1% of Quebec payroll less any
 * recorded eligible training (not yet tracked, so 0), payable to Revenu Québec.
 */
class Rl1SlipCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /** WSDRF rate: 1% of Quebec payroll, in basis points. */
    private const WSDRF_RATE_BP = 100;

    /**
     * @return array<int, array{
     *   contact_id: int, name: string, sin_last4: ?string,
     *   boxA: int, boxB: int, boxE: int, boxG: int, boxH: int, boxI: int
     * }>
     */
    public function slipsForYear(Company $company, int $year): array
    {
        $lineTotals = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->groupBy('prl.contact_id')
            ->selectRaw('
                prl.contact_id AS contact_id,
                COALESCE(SUM(prl.gross_cents), 0) AS box_a,
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)
                    + COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)), 0) AS box_b,
                COALESCE(SUM(COALESCE(prl.quebec_tax_override_cents, prl.quebec_tax_computed_cents)), 0) AS box_e,
                COALESCE(SUM(prl.cpp_pensionable_cents), 0) AS box_g,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)), 0) AS box_h,
                COALESCE(SUM(prl.qpip_insurable_cents), 0) AS box_i
            ')
            ->get()
            ->keyBy('contact_id');

        if ($lineTotals->isEmpty()) {
            return [];
        }

        $contacts = DB::table('contacts')
            ->leftJoin('employee_payroll_profiles as epp', 'epp.contact_id', '=', 'contacts.id')
            ->whereIn('contacts.id', $lineTotals->keys()->all())
            ->get(['contacts.id', 'contacts.display_name', 'epp.sin_last4'])
            ->keyBy('id');

        $slips = [];

        foreach ($lineTotals as $contactId => $row) {
            $contact = $contacts->get($contactId);

            $slips[] = [
                'contact_id' => (int) $contactId,
                'name' => (string) ($contact->display_name ?? ''),
                'sin_last4' => $contact->sin_last4 ?? null,
                'boxA' => (int) $row->box_a,
                'boxB' => (int) $row->box_b,
                'boxE' => (int) $row->box_e,
                'boxG' => (int) $row->box_g,
                'boxH' => (int) $row->box_h,
                'boxI' => (int) $row->box_i,
            ];
        }

        usort($slips, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $slips;
    }

    /**
     * Year totals across all RL-1 slips, plus the employer QPP/QPIP/QHSF totals
     * and the WSDRF reconciliation, for the RL-1 Summary.
     *
     * @return array{
     *   slip_count: int, boxA: int, boxB: int, boxE: int, boxG: int, boxH: int, boxI: int,
     *   employer_qpp: int, employer_qpip: int, qhsf: int,
     *   wsdrf_applicable: bool, wsdrf_payroll_cents: int, wsdrf_training_cents: int, wsdrf_levy_cents: int
     * }
     */
    public function summary(Company $company, int $year): array
    {
        $slips = $this->slipsForYear($company, $year);

        $employer = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->where('prl.province_of_employment', 'QC')
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->selectRaw('
                COALESCE(SUM(COALESCE(prl.qpp_employer_override_cents, prl.qpp_employer_computed_cents)
                    + COALESCE(prl.qpp2_employer_override_cents, prl.qpp2_employer_computed_cents)), 0) AS er_qpp,
                COALESCE(SUM(COALESCE(prl.qpip_employer_override_cents, prl.qpip_employer_computed_cents)), 0) AS er_qpip,
                COALESCE(SUM(prl.qhsf_employer_computed_cents), 0) AS qhsf
            ')
            ->first();

        $payroll = array_sum(array_column($slips, 'boxA'));
        $applicable = (bool) $company->wsdrf_applicable;
        // Eligible training spend is not yet tracked, so the reconciliation shows the full 1% due.
        $training = 0;
        $levy = $applicable ? max(0, (int) round($payroll * self::WSDRF_RATE_BP / 10000) - $training) : 0;

        return [
            'slip_count' => count($slips),
            'boxA' => $payroll,
            'boxB' => array_sum(array_column($slips, 'boxB')),
            'boxE' => array_sum(array_column($slips, 'boxE')),
            'boxG' => array_sum(array_column($slips, 'boxG')),
            'boxH' => array_sum(array_column($slips, 'boxH')),
            'boxI' => array_sum(array_column($slips, 'boxI')),
            'employer_qpp' => (int) ($employer->er_qpp ?? 0),
            'employer_qpip' => (int) ($employer->er_qpip ?? 0),
            'qhsf' => (int) ($employer->qhsf ?? 0),
            'wsdrf_applicable' => $applicable,
            'wsdrf_payroll_cents' => $payroll,
            'wsdrf_training_cents' => $training,
            'wsdrf_levy_cents' => $levy,
        ];
    }
}
