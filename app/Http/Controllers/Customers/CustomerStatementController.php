<?php

namespace App\Http\Controllers\Customers;

use App\Enums\CustomerStatementType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CustomerStatementPdfRenderer;
use App\Support\Reporting\StatementColumns;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a customer-facing statement PDF (open invoices or account activity)
 * for one customer, inline for print/preview or as a download — the export of
 * the statement page (customers.statement), which builds these links.
 *
 * Query: type, start, end (as_of is accepted for the open-invoices end date),
 * range (a `range=all` drops the open-invoices lower bound) and cols (optional
 * column keys, `none` for none; absent → the company's saved default). With no
 * parameters it prints today's open-invoices statement.
 */
class CustomerStatementController extends Controller
{
    public function print(Request $request, Company $company, Contact $contact, CustomerStatementPdfRenderer $renderer): Response
    {
        [$type, $start, $end, $columns] = $this->resolve($request, $company, $contact);

        return $renderer->inline($company, $contact, $type, $start, $end, $columns);
    }

    public function download(Request $request, Company $company, Contact $contact, CustomerStatementPdfRenderer $renderer): BinaryFileResponse
    {
        [$type, $start, $end, $columns] = $this->resolve($request, $company, $contact);

        return $renderer->download($company, $contact, $type, $start, $end, $columns);
    }

    /**
     * @return array{0: CustomerStatementType, 1: ?CarbonImmutable, 2: CarbonImmutable, 3: list<string>|null}
     */
    private function resolve(Request $request, Company $company, Contact $contact): array
    {
        abort_unless($contact->company_id === $company->id && $contact->is_customer, 404);

        $type = CustomerStatementType::tryFrom((string) $request->query('type')) ?? CustomerStatementType::OpenInvoices;

        $today = $company->currentDateTime()->startOfDay();
        $start = self::date($request->query('start'));

        if ($type === CustomerStatementType::OpenInvoices) {
            $end = self::date($request->query('as_of')) ?? self::date($request->query('end')) ?? $today;

            // No lower bound unless one was asked for — the statement's "All".
            if ($request->query('range') === 'all') {
                $start = null;
            }
        } else {
            $end = self::date($request->query('end')) ?? $today;
            $start ??= $today->startOfYear();
        }

        return [$type, $start, $end, StatementColumns::parse($request->query('cols'))];
    }

    /** A real Y-m-d from the query string, or null. */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? CarbonImmutable::parse($value) : null;
    }
}
