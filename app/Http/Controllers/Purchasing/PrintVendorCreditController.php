<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\VendorCredit;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintVendorCreditController extends Controller
{
    public function __invoke(Company $company, VendorCredit $vendor_credit, PdfExporter $pdf): Response
    {
        abort_unless($vendor_credit->company_id === $company->id, 404);

        $vendor_credit->loadMissing('lines.taxCode', 'lines.item', 'contact');

        $filename = 'vendor-credit-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $vendor_credit->vendor_credit_no).'.pdf';

        return $pdf->inline('pdf.vendor-credits.vendor-credit', [
            'company' => $company,
            'vendorCredit' => $vendor_credit,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($vendor_credit),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows, built by grouping the lines on their tax code
     * and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(VendorCredit $vendorCredit): array
    {
        return LineTaxBreakdown::forLines($vendorCredit->lines);
    }
}
