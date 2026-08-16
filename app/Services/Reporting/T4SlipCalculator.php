<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a calendar year of POSTED pay-run lines into T4 slips, one per
 * employee, mapping the persisted effective amounts to the CRA T4 boxes. Reads
 * stored actuals only — never re-runs the engine — so a slip equals what was
 * actually paid and remitted (including overrides).
 *
 * Box map:
 *   14  Employment income          → sum of gross
 *   16  Employee CPP               → cpp_employee (effective; 0 for Quebec)
 *   16A Employee CPP2              → cpp2_employee (effective; 0 for Quebec)
 *   17  Employee QPP               → qpp_employee (effective; Quebec only)
 *   18  Employee EI                → ei_employee (effective)
 *   22  Income tax deducted        → federal + provincial + additional (effective; Quebec tax is on the RL-1, not here)
 *   24  EI insurable earnings      → ei_insurable (capped per period at the MIE)
 *   26  CPP pensionable earnings   → cpp_pensionable (capped per period at YAMPE)
 *   55  Employee QPIP premiums     → qpip_employee (effective; Quebec only)
 *   56  QPIP insurable earnings    → qpip_insurable (capped per period at the QPIP MIE; Quebec only)
 *   20  RPP / 44 union / 46 charitable / 40 taxable benefits → from deduction/earning t4_box
 */
class T4SlipCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array<int, array{
     *   contact_id: int, name: string, sin_last4: ?string, province: ?string,
     *   box14: int, box16: int, box16a: int, box17: int, box17a: int, box18: int, box22: int,
     *   box24: int, box26: int, box55: int, box56: int, other: array<string, int>
     * }>
     */
    public function slipsForYear(Company $company, int $year): array
    {
        $lineTotals = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->groupBy('prl.contact_id')
            ->selectRaw('
                prl.contact_id AS contact_id,
                COALESCE(SUM(prl.gross_cents), 0) AS box14,
                COALESCE(SUM(COALESCE(prl.cpp_employee_override_cents, prl.cpp_employee_computed_cents)), 0) AS box16,
                COALESCE(SUM(COALESCE(prl.cpp2_employee_override_cents, prl.cpp2_employee_computed_cents)), 0) AS box16a,
                COALESCE(SUM(COALESCE(prl.qpp_employee_override_cents, prl.qpp_employee_computed_cents)), 0) AS box17,
                COALESCE(SUM(COALESCE(prl.qpp2_employee_override_cents, prl.qpp2_employee_computed_cents)), 0) AS box17a,
                COALESCE(SUM(COALESCE(prl.ei_employee_override_cents, prl.ei_employee_computed_cents)), 0) AS box18,
                COALESCE(SUM(COALESCE(prl.federal_tax_override_cents, prl.federal_tax_computed_cents)
                    + COALESCE(prl.provincial_tax_override_cents, prl.provincial_tax_computed_cents)
                    + COALESCE(prl.additional_tax_override_cents, prl.additional_tax_computed_cents)), 0) AS box22,
                COALESCE(SUM(prl.ei_insurable_cents), 0) AS box24,
                COALESCE(SUM(prl.cpp_pensionable_cents), 0) AS box26,
                COALESCE(SUM(COALESCE(prl.qpip_employee_override_cents, prl.qpip_employee_computed_cents)), 0) AS box55,
                COALESCE(SUM(prl.qpip_insurable_cents), 0) AS box56
            ')
            ->get()
            ->keyBy('contact_id');

        if ($lineTotals->isEmpty()) {
            return [];
        }

        $contactIds = $lineTotals->keys()->all();
        $otherBoxes = $this->otherBoxes($company, $year, $contactIds);
        $basesOnly = $this->basesOnlyTaxableByContact($company, $year, $contactIds);

        $contacts = DB::table('contacts')
            ->leftJoin('employee_payroll_profiles as epp', 'epp.contact_id', '=', 'contacts.id')
            ->whereIn('contacts.id', $contactIds)
            ->get(['contacts.id', 'contacts.display_name', 'epp.sin_last4', 'epp.province_of_employment'])
            ->keyBy('id');

        $slips = [];

        foreach ($lineTotals as $contactId => $row) {
            $contact = $contacts->get($contactId);

            $slips[] = [
                'contact_id' => (int) $contactId,
                'name' => (string) ($contact->display_name ?? ''),
                'sin_last4' => $contact->sin_last4 ?? null,
                'province' => $contact->province_of_employment ?? null,
                // Taxable benefits sit in gross_cents-excluded bases-only earnings; they
                // ARE employment income, so box 14 adds them back. Boxes 24/26 already
                // include them via the insurable/pensionable columns.
                'box14' => (int) $row->box14 + ($basesOnly[(int) $contactId] ?? 0),
                'box16' => (int) $row->box16,
                'box16a' => (int) $row->box16a,
                'box17' => (int) $row->box17,
                'box17a' => (int) $row->box17a,
                'box18' => (int) $row->box18,
                'box22' => (int) $row->box22,
                'box24' => (int) $row->box24,
                'box26' => (int) $row->box26,
                'box55' => (int) $row->box55,
                'box56' => (int) $row->box56,
                'other' => $otherBoxes[$contactId] ?? [],
            ];
        }

        usort($slips, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $slips;
    }

    /**
     * Year totals across all slips, for the T4 Summary.
     *
     * @return array{slip_count: int, box14: int, box16: int, box16a: int, box17: int, box17a: int, box18: int, box22: int, box24: int, box26: int, box55: int, box56: int, employer_cpp: int, employer_ei: int}
     */
    public function summary(Company $company, int $year): array
    {
        $slips = $this->slipsForYear($company, $year);

        $employer = DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.company_id', $company->id)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->selectRaw('
                COALESCE(SUM(COALESCE(prl.cpp_employer_override_cents, prl.cpp_employer_computed_cents)
                    + COALESCE(prl.cpp2_employer_override_cents, prl.cpp2_employer_computed_cents)), 0) AS er_cpp,
                COALESCE(SUM(COALESCE(prl.ei_employer_override_cents, prl.ei_employer_computed_cents)), 0) AS er_ei
            ')
            ->first();

        return [
            'slip_count' => count($slips),
            'box14' => array_sum(array_column($slips, 'box14')),
            'box16' => array_sum(array_column($slips, 'box16')),
            'box16a' => array_sum(array_column($slips, 'box16a')),
            'box17' => array_sum(array_column($slips, 'box17')),
            'box17a' => array_sum(array_column($slips, 'box17a')),
            'box18' => array_sum(array_column($slips, 'box18')),
            'box22' => array_sum(array_column($slips, 'box22')),
            'box24' => array_sum(array_column($slips, 'box24')),
            'box26' => array_sum(array_column($slips, 'box26')),
            'box55' => array_sum(array_column($slips, 'box55')),
            'box56' => array_sum(array_column($slips, 'box56')),
            'employer_cpp' => (int) ($employer->er_cpp ?? 0),
            'employer_ei' => (int) ($employer->er_ei ?? 0),
        ];
    }

    /**
     * "Other information" box totals per employee, from earning/deduction rows
     * tagged with a t4_box (RPP, union dues, charitable, taxable benefits, etc.).
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, array<string, int>>
     */
    private function otherBoxes(Company $company, int $year, array $contactIds): array
    {
        $rows = collect();

        foreach (['pay_run_line_earnings', 'pay_run_line_deductions', 'pay_run_line_contributions'] as $table) {
            $rows = $rows->merge(
                DB::table($table.' as x')
                    ->join('pay_run_lines as prl', 'prl.id', '=', 'x.pay_run_line_id')
                    ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
                    ->where('x.company_id', $company->id)
                    ->whereIn('pr.status', self::POSTED)
                    ->whereYear('pr.pay_date', $year)
                    ->whereNotNull('x.t4_box')
                    ->where('x.t4_box', '!=', '14') // box 14 is the headline employment income
                    ->whereIn('prl.contact_id', $contactIds)
                    ->groupBy('prl.contact_id', 'x.t4_box')
                    ->selectRaw('prl.contact_id AS contact_id, x.t4_box AS t4_box, COALESCE(SUM(x.amount_cents), 0) AS amount')
                    ->get()
            );
        }

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->contact_id][$row->t4_box] = ($result[(int) $row->contact_id][$row->t4_box] ?? 0) + (int) $row->amount;
        }

        return $result;
    }

    /**
     * Sum of taxable bases-only earnings (taxable employer benefits) per employee.
     * They feed the source-deduction bases but are excluded from gross_cents, so
     * box 14 (employment income) must add them back — they ARE employment income.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, int>
     */
    private function basesOnlyTaxableByContact(Company $company, int $year, array $contactIds): array
    {
        return DB::table('pay_run_line_earnings as e')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'e.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('e.company_id', $company->id)
            ->whereIn('pr.status', self::POSTED)
            ->whereYear('pr.pay_date', $year)
            ->where('e.add_to_bases_only', true)
            ->where('e.is_taxable', true)
            ->whereIn('prl.contact_id', $contactIds)
            ->groupBy('prl.contact_id')
            ->selectRaw('prl.contact_id AS contact_id, COALESCE(SUM(e.amount_cents), 0) AS amount')
            ->pluck('amount', 'contact_id')
            ->map(fn ($a) => (int) $a)
            ->all();
    }
}
