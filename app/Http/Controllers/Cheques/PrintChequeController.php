<?php

namespace App\Http\Controllers\Cheques;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Models\Company;
use App\Services\Printing\ChequePdfRenderer;
use Illuminate\Http\Response;

class PrintChequeController extends Controller
{
    public function __invoke(Company $company, Cheque $cheque, ChequePdfRenderer $renderer): Response
    {
        abort_unless($cheque->company_id === $company->id, 404);
        abort_if(blank($cheque->cheque_no), 404, 'Cheque # is required to print a cheque.');

        $filename = 'cheque-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $cheque->cheque_no).'.pdf';

        return new Response($renderer->render($cheque), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
