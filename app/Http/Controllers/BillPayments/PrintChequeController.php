<?php

namespace App\Http\Controllers\BillPayments;

use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Models\Company;
use App\Services\Printing\ChequePdfRenderer;
use Illuminate\Http\Response;

class PrintChequeController extends Controller
{
    public function __invoke(Company $company, BillPayment $payment, ChequePdfRenderer $renderer): Response
    {
        abort_unless($payment->company_id === $company->id, 404);
        abort_unless((bool) $payment->paymentMethod?->is_cheque, 404);
        abort_if(blank($payment->reference), 404, 'Cheque # is required to print a cheque.');

        $filename = 'cheque-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payment->reference).'.pdf';

        return new Response($renderer->render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
