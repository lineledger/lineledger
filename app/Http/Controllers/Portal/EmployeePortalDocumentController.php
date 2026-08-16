<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SlipType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payroll\PrintPayStubController;
use App\Models\Company;
use App\Models\PayrollSlipFilingLine;
use App\Models\PayRunLine;
use App\Services\Pdf\Slips\T4SlipPdfAdapter;
use App\Services\Reporting\PayStatementYtdCalculator;
use App\Services\Reporting\PdfExporter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an employee their own pay-statement, T4 and RL-1 PDFs from the
 * self-service portal. Every endpoint independently re-verifies that the
 * requested document belongs to the signed-in employee (defence in depth on
 * top of the portal.audience:employee middleware): the signed-in contact id is
 * the only ownership key, and a contact can never reach another employee's
 * documents.
 *
 * The PDFs reuse the staff-side Blade templates unchanged. Tax slips are
 * served from the FINALIZED filing snapshot only — a year the employer has
 * not finalized (or has unlocked to amend) 404s, never a live recomputation.
 */
class EmployeePortalDocumentController extends Controller
{
    public function payStub(
        Company $company,
        PayRunLine $payRunLine,
        PrintPayStubController $printer,
        PdfExporter $pdf,
        PayStatementYtdCalculator $ytdCalc,
    ): Response {
        abort_unless($payRunLine->company_id === $company->id, 404);
        abort_unless($payRunLine->contact_id === (int) auth('customer')->id(), 404);

        $payRunLine->loadMissing('payRun');

        // Only a posted/paid run is an official statement; a draft or
        // calculated-but-unposted run is still in progress.
        abort_unless($payRunLine->payRun->isPosted(), 404);

        $data = $printer->viewData($company, $payRunLine, $ytdCalc);

        $filename = 'pay-statement-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payRunLine->payRun->run_no).'-'.$payRunLine->contact_id.'.pdf';

        return $pdf->inline('pdf.reports.pay-stub', $data, $filename);
    }

    public function t4(Company $company, int $year, PdfExporter $pdf): BinaryFileResponse
    {
        return $this->slipPdf($company, SlipType::T4, $year, $pdf, 'pdf.reports.t4-slip', 't4-'.$year.'.pdf');
    }

    public function rl1(Company $company, int $year, PdfExporter $pdf): BinaryFileResponse
    {
        return $this->slipPdf($company, SlipType::Rl1, $year, $pdf, 'pdf.reports.rl1-slip', 'rl1-'.$year.'.pdf');
    }

    /**
     * Renders a slip PDF from the finalized filing's snapshot line. The line is
     * selected by the signed-in contact id (the ownership boundary), and a year
     * with no finalized filing — or no line for this employee — 404s.
     */
    private function slipPdf(Company $company, SlipType $type, int $year, PdfExporter $pdf, string $view, string $filename): BinaryFileResponse
    {
        $line = PayrollSlipFilingLine::query()
            ->where('company_id', $company->id)
            ->where('contact_id', (int) auth('customer')->id())
            ->whereHas('filing', fn ($q) => $q
                ->where('slip_type', $type->value)
                ->where('year', $year))
            ->first();

        abort_if($line === null, 404);

        // Official CRA template first (same renderer as the staff page, fed by
        // the finalized snapshot); the labelled facsimile when no template/map
        // is installed for the year.
        if ($type === SlipType::T4) {
            // The LINE's contact_id is remapped on contact merge; the id inside
            // the JSON snapshot is not — pass the authoritative one so the
            // full-SIN/address lookups still resolve.
            $official = app(T4SlipPdfAdapter::class)->render($company, (array) $line->data, $year, (int) $line->contact_id);

            if ($official !== null) {
                return $pdf->downloadRaw($official, $filename);
            }
        }

        return $pdf->download($view, [
            'company' => $company,
            'slip' => $line->data,
            'year' => $year,
            'facsimile' => $type === SlipType::T4,
        ], $filename);
    }
}
