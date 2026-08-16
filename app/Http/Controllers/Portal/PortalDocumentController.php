<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AccountSubtype;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Reporting\ContactStatementBuilder;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Services\Reporting\PdfExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortalDocumentController extends Controller
{
    /**
     * Stream an invoice PDF, but only one belonging to the signed-in customer.
     */
    public function invoice(Company $company, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        $this->authorizeInvoice($company, $invoice);

        return $renderer->inline($company, $invoice);
    }

    /**
     * Download the signed-in customer's own AR statement as a PDF.
     */
    public function statement(Request $request, Company $company, ContactStatementBuilder $builder, PdfExporter $pdf): BinaryFileResponse
    {
        $customer = Auth::guard('customer')->user();

        $start = CarbonImmutable::parse($request->query('start', $company->currentDateTime()->startOfYear()->toDateString()));
        $end = CarbonImmutable::parse($request->query('end', $company->currentDateTime()->toDateString()));

        $report = $builder->build($company, $customer, AccountSubtype::AccountsReceivable, $start, $end);

        return $pdf->download('pdf.reports.contact-statement', [
            'company' => $company,
            'contact' => $customer,
            'title' => __('Account Statement'),
            'report' => $report,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
        ], 'statement-'.$start->toDateString().'-to-'.$end->toDateString().'.pdf');
    }

    /**
     * Ensure the invoice belongs to both this company and the signed-in customer.
     */
    private function authorizeInvoice(Company $company, Invoice $invoice): void
    {
        $customer = Auth::guard('customer')->user();

        abort_unless(
            $invoice->company_id === $company->id && $customer !== null && $invoice->contact_id === $customer->id,
            404,
        );
    }
}
