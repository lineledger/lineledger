<?php

namespace App\Http\Controllers\Bills;

use App\Enums\BillType;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Company;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintBillController extends Controller
{
    public function __invoke(Company $company, Bill $bill, PdfExporter $pdf): Response
    {
        abort_unless($bill->company_id === $company->id, 404);

        $bill->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms');

        $isReimbursement = $bill->bill_type === BillType::Reimbursement;
        $slug = $isReimbursement ? 'reimbursement' : 'bill';

        $filename = $slug.'-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $bill->bill_no).'.pdf';

        return $pdf->inline('pdf.bills.bill', [
            'company' => $company,
            'bill' => $bill,
            'isReimbursement' => $isReimbursement,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($bill),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows (e.g. "GST 5.00% … 17.05"), built by grouping
     * the bill lines on their tax code and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(Bill $bill): array
    {
        return LineTaxBreakdown::forLines($bill->lines);
    }
}
