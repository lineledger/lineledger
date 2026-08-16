<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SalesReceipt;
use App\Services\Reporting\PdfExporter;
use Illuminate\Http\Response;

class PrintSalesReceiptController extends Controller
{
    public function __invoke(Company $company, SalesReceipt $receipt, PdfExporter $pdf): Response
    {
        abort_unless($receipt->company_id === $company->id, 404);

        $receipt->loadMissing('contact', 'paymentMethod', 'depositToAccount', 'lines.taxCode');

        $filename = 'sales-receipt-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $receipt->sales_receipt_no).'.pdf';

        return $pdf->inline('pdf.sales-receipts.receipt', [
            'company' => $company,
            'receipt' => $receipt,
            'settings' => $company->invoiceSettingsOrNew(),
        ], $filename);
    }
}
