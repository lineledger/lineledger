<?php

namespace App\Http\Controllers\BillPayments;

use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Models\Company;
use App\Services\Reporting\PdfExporter;
use Illuminate\Http\Response;

class PrintBillPaymentController extends Controller
{
    public function __invoke(Company $company, BillPayment $payment, PdfExporter $pdf): Response
    {
        abort_unless($payment->company_id === $company->id, 404);

        $payment->loadMissing('contact', 'paymentMethod', 'applications.bill');

        $filename = 'payment-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payment->payment_no).'.pdf';

        return $pdf->inline('pdf.bill-payments.payment', [
            'company' => $company,
            'payment' => $payment,
            'settings' => $company->invoiceSettingsOrNew(),
        ], $filename);
    }
}
