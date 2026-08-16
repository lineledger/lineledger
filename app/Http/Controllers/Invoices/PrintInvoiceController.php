<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use Illuminate\Http\Response;

class PrintInvoiceController extends Controller
{
    public function __invoke(Company $company, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        abort_unless($invoice->company_id === $company->id, 404);

        return $renderer->inline($company, $invoice);
    }
}
