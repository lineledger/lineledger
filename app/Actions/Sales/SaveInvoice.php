<?php

namespace App\Actions\Sales;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates an invoice header and its line items, recalculating totals.
 * Shared by the Livewire form and the API. Does NOT post — the caller decides
 * whether to hand the result to InvoicePoster.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:       int
 *   sales_rep_id:     ?int     (employee credited with the sale)
 *   sales_order_id:   ?int     (set once at creation when fulfilling a sales order)
 *   invoice_no:       ?string  (null → auto-generated)
 *   invoice_date:     string
 *   due_date:         ?string  (null → derived from terms, else invoice_date)
 *   ship_date:        ?string
 *   ship_via:         ?string
 *   fob:              ?string
 *   tracking_no:      ?string
 *   customer_po:      ?string
 *   terms_id:         ?int
 *   form_style_id:    ?int     (sales-form template used when rendering the PDF)
 *   memo:             ?string
 *   customer_message: ?string
 *   lines: array<int, array{
 *     item_id: ?int, sales_order_line_id: ?int, account_id: int, description: ?string,
 *     service_date: ?string, quantity: string|int|float, unit_price_cents: int,
 *     line_discount_cents: ?int, line_discount_pct: ?string, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int
 *   }>
 */
final class SaveInvoice
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxCalculator $taxCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $invoice): Invoice {
            $company = app('current_company');
            $invoiceDate = CarbonImmutable::parse($data['invoice_date']);
            $dueDate = $this->resolveDueDate($data, $invoiceDate);

            $header = [
                'contact_id' => $data['contact_id'],
                'sales_rep_id' => $data['sales_rep_id'] ?? null,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'ship_date' => ! empty($data['ship_date'])
                    ? CarbonImmutable::parse($data['ship_date'])->toDateString()
                    : null,
                'ship_via' => $data['ship_via'] ?? null,
                'fob' => $data['fob'] ?? null,
                'tracking_no' => $data['tracking_no'] ?? null,
                'customer_po' => $data['customer_po'] ?? null,
                'terms_id' => $data['terms_id'] ?? null,
                'form_style_id' => $data['form_style_id'] ?? null,
                'memo' => $data['memo'] ?? null,
                'customer_message' => $data['customer_message'] ?? null,
            ];

            if ($invoice && $invoice->exists) {
                $invoice->update($header);
            } else {
                $invoice = Invoice::create($header + [
                    'invoice_no' => $data['invoice_no']
                        ?? $this->numbers->next($company, Invoice::class, 'invoice_no', 'INV'),
                    // Set only at creation so a later edit can't wipe the link.
                    'sales_order_id' => $data['sales_order_id'] ?? null,
                    'status' => InvoiceStatus::Draft,
                    // A document transacts in the contact's currency (null = home).
                    // Fixed at creation; the rate is locked later, at posting.
                    'currency_code' => $this->resolveCurrencyCode($company, $data),
                ]);
            }

            $invoice->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $taxCode = isset($line['tax_code_id'])
                    ? TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->find($line['tax_code_id'])
                    : null;

                $secondaryTaxCode = isset($line['secondary_tax_code_id'])
                    ? TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->find($line['secondary_tax_code_id'])
                    : null;

                $totals = $this->taxCalculator->line(
                    (string) $line['quantity'],
                    (int) $line['unit_price_cents'],
                    $taxCode,
                    (int) ($line['line_discount_cents'] ?? 0),
                    $line['line_discount_pct'] ?? null,
                    (int) ($line['line_markup_cents'] ?? 0),
                    $line['line_markup_pct'] ?? null,
                    $secondaryTaxCode,
                );

                $invoice->lines()->create([
                    'description' => $line['description'] ?? null,
                    'service_date' => ! empty($line['service_date'])
                        ? CarbonImmutable::parse($line['service_date'])->toDateString()
                        : null,
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_discount_cents' => $totals['discount_cents'],
                    'line_discount_pct' => $line['line_discount_pct'] ?? null,
                    'line_markup_cents' => $totals['markup_cents'],
                    'line_markup_pct' => $line['line_markup_pct'] ?? null,
                    'account_id' => $line['account_id'],
                    'item_id' => $line['item_id'] ?? null,
                    'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                    'tax_code_id' => $taxCode?->id,
                    'secondary_tax_code_id' => $secondaryTaxCode?->id,
                    'line_subtotal_cents' => $totals['subtotal_cents'],
                    'line_tax_cents' => $totals['tax_cents'],
                    'secondary_tax_cents' => $totals['secondary_tax_cents'],
                    'line_total_cents' => $totals['total_cents'],
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            $invoice->refresh();

            // Document-level discount: a percent of the pre-discount subtotal, or a
            // fixed amount. Clamped to the subtotal so it can never invert the total.
            $subtotal = (int) $invoice->lines->sum('line_subtotal_cents');
            $docPct = $data['document_discount_pct'] ?? null;
            $docDiscount = $docPct !== null && $docPct !== ''
                ? (int) round($subtotal * (float) $docPct / 100)
                : (int) ($data['document_discount_cents'] ?? 0);
            $docDiscount = max(0, min($docDiscount, $subtotal));

            $invoice->forceFill([
                'document_discount_pct' => $docPct !== null && $docPct !== '' ? $docPct : null,
                'document_discount_cents' => $docDiscount,
            ])->save();

            $invoice->recalculateTotals();

            return $invoice;
        });
    }

    /**
     * The document's currency: an explicit override, else the contact's currency.
     * Returns null for the home currency so single-currency invoices stay null.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCurrencyCode(Company $company, array $data): ?string
    {
        $code = $data['currency_code'] ?? Contact::find($data['contact_id'])?->currency_code;

        if ($code === null || $company->isHomeCurrency($code)) {
            return null;
        }

        return mb_strtoupper($code);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveDueDate(array $data, CarbonImmutable $invoiceDate): CarbonImmutable
    {
        if (! empty($data['due_date'])) {
            return CarbonImmutable::parse($data['due_date']);
        }

        if (! empty($data['terms_id'])) {
            $term = PaymentTerm::find($data['terms_id']);
            if ($term) {
                return $term->dueDateFrom($invoiceDate);
            }
        }

        return $invoiceDate;
    }
}
