<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintCreditMemoController extends Controller
{
    public function __invoke(Company $company, CreditMemo $credit_memo, PdfExporter $pdf): Response
    {
        abort_unless($credit_memo->company_id === $company->id, 404);

        $credit_memo->loadMissing('lines.taxCode', 'lines.item', 'contact', 'salesRep');

        $filename = 'credit-memo-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $credit_memo->credit_memo_no).'.pdf';

        return $pdf->inline('pdf.credit-memos.credit-memo', [
            'company' => $company,
            'creditMemo' => $credit_memo,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($credit_memo),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows (e.g. "GST 5.00% … 17.05"), built by grouping
     * the credit memo lines on their tax code and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(CreditMemo $creditMemo): array
    {
        return LineTaxBreakdown::forLines($creditMemo->lines);
    }
}
