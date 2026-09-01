<?php

namespace App\Http\Controllers\Customers;

use App\Enums\CustomerStatementType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CustomerStatementPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a customer-facing statement PDF (open invoices or account activity)
 * for one customer, inline for print/preview or as a download.
 */
class CustomerStatementController extends Controller
{
    public function print(Request $request, Company $company, Contact $contact, CustomerStatementPdfRenderer $renderer): Response
    {
        [$type, $start, $end] = $this->resolve($request, $company, $contact);

        return $renderer->inline($company, $contact, $type, $start, $end);
    }

    public function download(Request $request, Company $company, Contact $contact, CustomerStatementPdfRenderer $renderer): BinaryFileResponse
    {
        [$type, $start, $end] = $this->resolve($request, $company, $contact);

        return $renderer->download($company, $contact, $type, $start, $end);
    }

    /**
     * @return array{0: CustomerStatementType, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function resolve(Request $request, Company $company, Contact $contact): array
    {
        abort_unless($contact->company_id === $company->id && $contact->is_customer, 404);

        $type = CustomerStatementType::tryFrom((string) $request->query('type')) ?? CustomerStatementType::OpenInvoices;

        $today = $company->currentDateTime()->toDateString();

        $start = CarbonImmutable::parse($request->query('start', $company->currentDateTime()->startOfYear()->toDateString()));
        $end = CarbonImmutable::parse($type === CustomerStatementType::OpenInvoices
            ? $request->query('as_of', $today)
            : $request->query('end', $today));

        return [$type, $start, $end];
    }
}
