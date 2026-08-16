<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\JurisdictionCapability;
use App\Enums\PayRunStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayRunLine;
use App\Services\Reporting\PayStatementYtdCalculator;
use App\Services\Reporting\PdfExporter;
use App\Support\Payroll\PayStatementJurisdiction;
use Illuminate\Http\Response;

class PrintPayStubController extends Controller
{
    public function __invoke(Company $company, PayRunLine $payRunLine, PdfExporter $pdf, PayStatementYtdCalculator $ytdCalc): Response
    {
        abort_unless($payRunLine->company_id === $company->id, 404);
        abort_unless($company->supports(JurisdictionCapability::Payroll), 404);

        $payRunLine->loadMissing('contact', 'earnings', 'deductions', 'contributions', 'accruals', 'payRun', 'profile');

        // The statement needs calculated figures — a draft run has none yet.
        abort_if($payRunLine->payRun->status === PayRunStatus::Draft, 404, 'Calculate the pay run first.');

        $data = $this->viewData($company, $payRunLine, $ytdCalc);

        $filename = 'pay-statement-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payRunLine->payRun->run_no).'-'.$payRunLine->contact_id.'.pdf';

        return $pdf->inline('pdf.reports.pay-stub', $data, $filename);
    }

    /**
     * Assemble the province-aware statement view data: the jurisdiction profile,
     * YTD figures, item visibility, statutory deductions, and the cash-vs-benefit
     * earnings split. Public so it can be rendered and asserted in isolation.
     *
     * @return array<string, mixed>
     */
    public function viewData(Company $company, PayRunLine $payRunLine, PayStatementYtdCalculator $ytdCalc): array
    {
        $payRunLine->loadMissing('contact', 'earnings', 'deductions', 'contributions', 'accruals', 'payRun', 'profile');

        $province = (string) $payRunLine->province_of_employment;
        $federal = (bool) $company->payroll_federally_regulated;
        $isQuebec = mb_strtoupper($province) === 'QC';

        // The statement name, legislation and required-item set for the jurisdiction
        // where the work is performed (or the federal CLC when federally regulated).
        $jurisdiction = PayStatementJurisdiction::forProvince($province, $federal);

        $ytd = $ytdCalc->forLine($payRunLine);

        // Item visibility: a legislatively required item is locked on; an optional
        // item follows the employer's toggle (default on). benefits_section isn't a
        // legislated line, so it's purely the employer's preference.
        $show = [];
        foreach (['ytd', 'rate', 'hours', 'employer_address', 'occupation'] as $key) {
            $show[$key] = PayStatementJurisdiction::requires($province, $key, $federal)
                || $company->payStatementSetting($key, true);
        }
        $show['benefits_section'] = $company->payStatementSetting('benefits_section', true);

        // Statutory employee deductions with YTD, in statement order, zeros dropped
        // (so a rest-of-Canada statement shows CPP, a Quebec one shows QPP/QPIP).
        $s = $ytd['statutory'];
        $statutory = collect([
            $isQuebec
                ? $this->stat(__('QPP'), $s['qpp_employee'], $s['qpp2_employee'])
                : $this->stat(__('CPP'), $s['cpp_employee'], $s['cpp2_employee']),
            $this->stat(__('EI'), $s['ei_employee']),
            $isQuebec ? $this->stat(__('QPIP'), $s['qpip_employee']) : ['label' => __('QPIP'), 'current' => 0, 'ytd' => 0],
            $this->stat(__('Income tax'), $s['income_tax']),
        ])->filter(fn (array $row) => $row['current'] > 0 || $row['ytd'] > 0)->values()->all();

        // Split earnings: cash (Earnings section) vs non-cash taxable benefits. A
        // bases-only earning that shares a code with a contribution is already shown
        // in the benefits section via that contribution, so it's not repeated here.
        $contributionCodes = array_keys($ytd['benefits']);
        $cashEarnings = array_filter($ytd['earnings'], fn (array $e) => ! $e['add_to_bases_only']);
        $benefitEarnings = array_filter(
            $ytd['earnings'],
            fn (array $e, string $code) => $e['add_to_bases_only'] && ! in_array($code, $contributionCodes, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return [
            'company' => $company,
            'line' => $payRunLine,
            'jurisdiction' => $jurisdiction,
            'ytd' => $ytd,
            'show' => $show,
            'statutory' => $statutory,
            'isQuebec' => $isQuebec,
            'cashEarnings' => $cashEarnings,
            'benefitEarnings' => $benefitEarnings,
        ];
    }

    /**
     * One statutory row {label, current, ytd}, optionally summing two components
     * (e.g. CPP + CPP2) that share a statement line.
     *
     * @param  array{current: int, ytd: int}  $a
     * @param  array{current: int, ytd: int}|null  $b
     * @return array{label: string, current: int, ytd: int}
     */
    private function stat(string $label, array $a, ?array $b = null): array
    {
        return [
            'label' => $label,
            'current' => $a['current'] + ($b['current'] ?? 0),
            'ytd' => $a['ytd'] + ($b['ytd'] ?? 0),
        ];
    }
}
