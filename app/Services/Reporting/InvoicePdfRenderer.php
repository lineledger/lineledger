<?php

namespace App\Services\Reporting;

use App\Enums\InvoiceLayout;
use App\Models\Company;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Support\Locales;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

/**
 * Single source for rendering an invoice to PDF — the view, tax summary and
 * embedded logo. Used by the staff print route, the customer portal, and invoice
 * emails so the document is identical everywhere.
 */
class InvoicePdfRenderer
{
    public function __construct(protected PdfExporter $pdf) {}

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoice->invoice_no).'.pdf';
    }

    /**
     * Inline response (browser PDF viewer / print dialog).
     */
    public function inline(Company $company, Invoice $invoice): Response
    {
        return Locales::using(
            $this->locale($company, $invoice),
            function () use ($company, $invoice): Response {
                $data = $this->data($company, $invoice);

                return $this->pdf->inline($this->view($data), $data, $this->filename($invoice));
            },
        );
    }

    /**
     * Raw PDF bytes — for attaching to an email.
     */
    public function raw(Company $company, Invoice $invoice): string
    {
        return Locales::using(
            $this->locale($company, $invoice),
            function () use ($company, $invoice): string {
                $data = $this->data($company, $invoice);

                return $this->pdf->raw($this->view($data), $data);
            },
        );
    }

    /**
     * HTML of the invoice blade (tests / previews). Honours document locale.
     */
    public function html(Company $company, Invoice $invoice): string
    {
        return Locales::using(
            $this->locale($company, $invoice),
            function () use ($company, $invoice): string {
                $data = $this->data($company, $invoice);

                return view($this->view($data), $data)->render();
            },
        );
    }

    /**
     * The view for the layout the invoice's style prints with; the classic
     * layout when there is no style.
     *
     * @param  array<string, mixed>  $data  from {@see data()}
     */
    private function view(array $data): string
    {
        return ($data['style']->layout ?? InvoiceLayout::Classic)->view();
    }

    /**
     * The invoice's chosen style, else the company default. The explicit
     * company_id filter matters for queued contexts (e.g. email sending) where
     * the `current_company` global scope is not bound. Null when the company has
     * no styles — the classic blade then renders exactly as before.
     */
    private function style(Company $company, Invoice $invoice): ?FormStyle
    {
        return $invoice->formStyle ?: FormStyle::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Company $company, Invoice $invoice): array
    {
        $invoice->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms', 'salesRep', 'formStyle');

        $style = $this->style($company, $invoice);

        return [
            'company' => $company,
            'invoice' => $invoice,
            'settings' => $company->invoiceSettingsOrNew(),
            'style' => $style,
            'taxSummary' => $this->taxSummary($invoice),
        ];
    }

    /**
     * Per-tax-code summary rows, grouping invoice lines on their tax code.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(Invoice $invoice): array
    {
        return LineTaxBreakdown::forLines($invoice->lines);
    }

    private function locale(Company $company, Invoice $invoice): string
    {
        $invoice->loadMissing('contact');

        return Locales::forDocument($invoice->contact, $company);
    }
}
