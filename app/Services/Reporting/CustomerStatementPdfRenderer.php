<?php

namespace App\Services\Reporting;

use App\Enums\CustomerStatementType;
use App\Models\Company;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Single source for rendering a customer statement to PDF. Used by the staff
 * print/download routes and the statement email so the document is identical
 * everywhere.
 *
 * $end doubles as the as-of date for the open-invoices type; $start is only
 * consulted for the activity type (defaulting to the start of the year).
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
     */
    public function inline(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end): Response
    {
        return $this->pdf->inline(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end),
            $this->filename($contact, $type, $end),
        );
    }

    public function download(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end): BinaryFileResponse
    {
        return $this->pdf->download(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end),
            $this->filename($contact, $type, $end),
        );
    }

    /**
     * Raw PDF bytes — for attaching to an email.
     */
    public function raw(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $this->pdf->raw(
            'pdf.statements.customer-statement',
            $this->data($company, $contact, $type, $start, $end),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end): array
    {
        $contact->loadMissing('parent');

        $data = $type === CustomerStatementType::OpenInvoices
            ? $this->builder->openInvoices($company, $contact, $end)
            : $this->builder->activity($company, $contact, $start ?? $end->startOfYear(), $end);

        return [
            'company' => $company,
            'contact' => $contact,
            'type' => $type,
            'settings' => $company->invoiceSettingsOrNew(),
            'data' => $data,
        ];
    }
}
