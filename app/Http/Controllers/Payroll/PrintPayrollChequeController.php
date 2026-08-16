<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\JurisdictionCapability;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayrollCheque;
use App\Services\Printing\ChequePdfRenderer;
use Illuminate\Http\Response;

class PrintPayrollChequeController extends Controller
{
    public function __invoke(Company $company, PayrollCheque $payrollCheque, ChequePdfRenderer $renderer): Response
    {
        abort_unless($payrollCheque->company_id === $company->id, 404);
        abort_unless($company->supports(JurisdictionCapability::Payroll), 404);
        abort_if(blank($payrollCheque->cheque_no), 404, 'Cheque # is required to print a cheque.');

        $filename = 'payroll-cheque-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $payrollCheque->cheque_no).'.pdf';

        return new Response($renderer->render($payrollCheque), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
