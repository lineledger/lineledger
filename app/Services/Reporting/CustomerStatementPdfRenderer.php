<?php

namespace App\Services\Reporting;

use App\Enums\CustomerStatementType;
use App\Models\Company;
use App\Models\Contact;
use App\Support\Reporting\StatementColumns;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Single source for rendering a customer statement to PDF. Used by the staff
 * print/download routes and the statement email so the document is identical
 * everywhere.
 *
 * $end doubles as the as-of date for the open-invoices type. $start is the
 * activity period's first day (defaulting to the start of the year), and for
 * open invoices the optional lower bound: null lists every open invoice, a
 * date carries the older ones forward as one "Balance forward" row.
 *
 * $columns are the OPTIONAL column keys to show (see StatementColumns); null
 * uses the company's saved default.
 */
class CustomerStatementPdfRenderer
{
    public function __construct(
        protected PdfExporter $pdf,
        protected CustomerStatementBuilder $builder,
    ) {}

    public function filename(Contact $contact, CustomerStatementType $type, CarbonImmutable $end): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', (string) $contact->display_name));

        return 'statement-'.strtolower(trim($name, '-')).'-'.$end->toDateString().'.pdf';
    }

    /**
     * Inline response (browser PDF viewer / print dialog).
     *
     * @param  list<string>|null  $columns
     */
    public function inline(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end, ?array $columns = null): Response
    {
        return $this->pdf->inline(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end, $columns),
            $this->filename($contact, $type, $end),
        );
    }

    /**
     * @param  list<string>|null  $columns
     */
    public function download(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end, ?array $columns = null): BinaryFileResponse
    {
        return $this->pdf->download(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end, $columns),
            $this->filename($contact, $type, $end),
        );
    }

    /**
     * Raw PDF bytes — for attaching to an email.
     *
     * @param  list<string>|null  $columns
     */
    public function raw(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end, ?array $columns = null): string
    {
        return $this->pdf->raw(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end, $columns),
        );
    }

    /**
     * The view data — public so tests can render the template without dompdf.
     *
     * @param  list<string>|null  $columns
     * @return array<string, mixed>
     */
    public function data(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end, ?array $columns = null): array
    {
        $contact->loadMissing('parent');

        $settings = $company->invoiceSettingsOrNew();

        $data = $type === CustomerStatementType::OpenInvoices
            ? $this->builder->openInvoices($company, $contact, $end, $start)
            : $this->builder->activity($company, $contact, $start ?? $end->startOfYear(), $end);

        return [
            'company' => $company,
            'contact' => $contact,
            'type' => $type,
            'settings' => $settings,
            'data' => $data,
            'columns' => StatementColumns::visible($type, $columns, $settings->statementColumnsFor($type)),
        ];
    }
}
