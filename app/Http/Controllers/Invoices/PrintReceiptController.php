<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Services\Reporting\PdfExporter;
use App\Support\Locales;
use Illuminate\Http\Response;

class PrintReceiptController extends Controller
{
    public function __invoke(Company $company, CustomerReceipt $receipt, PdfExporter $pdf): Response
    {
        abort_unless($receipt->company_id === $company->id, 404);

        $receipt->loadMissing('contact', 'paymentMethod', 'applications.invoice');

        $filename = 'receipt-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $receipt->receipt_no).'.pdf';

        return Locales::forContactDocument($receipt->contact, $company, fn (): Response => $pdf->inline('pdf.receipts.receipt', [
            'company' => $company,
            'receipt' => $receipt,
            'settings' => $company->invoiceSettingsOrNew(),
        ], $filename));
    }
}
