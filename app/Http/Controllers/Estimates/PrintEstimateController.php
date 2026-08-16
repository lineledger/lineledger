<?php

namespace App\Http\Controllers\Estimates;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Estimate;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintEstimateController extends Controller
{
    public function __invoke(Company $company, Estimate $estimate, PdfExporter $pdf): Response
    {
        abort_unless($estimate->company_id === $company->id, 404);

        $estimate->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms', 'salesRep');

        $filename = 'estimate-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $estimate->estimate_no).'.pdf';

        return $pdf->inline('pdf.estimates.estimate', [
            'company' => $company,
            'estimate' => $estimate,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($estimate),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows (e.g. "GST 5.00% … 17.05"), built by grouping
     * the estimate lines on their tax code and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(Estimate $estimate): array
    {
        return LineTaxBreakdown::forLines($estimate->lines);
    }
}
