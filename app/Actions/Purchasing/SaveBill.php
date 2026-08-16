<?php

namespace App\Actions\Purchasing;

use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a bill header and its line items, recalculating totals.
 * Shared by the Livewire forms (vendor bill + employee reimbursement) and the
 * API. Does NOT post — the caller decides whether to hand the result to
 * BillPoster.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:       int
 *   bill_type:        ?string  'vendor'|'reimbursement' (default vendor)
 *   bill_no:          ?string  (null → auto-generated, BILL/REIM prefix by type)
 *   vendor_reference: ?string
 *   bill_date:        string
 *   due_date:         ?string  (null → derived from terms, else bill_date)
 *   terms_id:         ?int
 *   memo:             ?string
 *   lines: array<int, array{
 *     item_id: ?int, account_id: int, description: ?string,
 *     quantity: string|int|float, unit_price_cents: int,
 *     line_discount_cents: ?int, line_discount_pct: ?string, tax_code_id: ?int,
 *     tax_override_cents: ?int, class_id: ?int, location_id: ?int
 *   }>
 *
 * tax_override_cents, when non-null, is the exact tax the user typed and wins
 * over the tax code's computed amount.
 */
final class SaveBill
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxCalculator $taxCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Bill $bill = null): Bill
    {
        return DB::transaction(function () use ($data, $bill): Bill {
            $company = app('current_company');
            $billType = isset($data['bill_type'])
                ? BillType::from($data['bill_type'])
                : ($bill?->bill_type ?? BillType::Vendor);

            $billDate = CarbonImmutable::parse($data['bill_date']);
            $dueDate = $this->resolveDueDate($data, $billDate);

            $header = [
                'contact_id' => $data['contact_id'],
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'bill_date' => $billDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'terms_id' => $data['terms_id'] ?? null,
                'memo' => $data['memo'] ?? null,
            ];

            if ($bill && $bill->exists) {
                $bill->update($header);
            } else {
                $prefix = $billType === BillType::Reimbursement ? 'REIM' : 'BILL';

                $bill = Bill::create($header + [
                    'bill_type' => $billType,
                    'bill_no' => $data['bill_no']
                        ?? $this->numbers->next($company, Bill::class, 'bill_no', $prefix),
                    // Set only at creation so a later edit can't wipe the link to the
                    // purchase order this bill receives against.
                    'purchase_order_id' => $data['purchase_order_id'] ?? null,
                    'status' => BillStatus::Draft,
                    'currency_code' => $this->resolveCurrencyCode($company, $data),
                ]);
            }

            $bill->lines()->delete();

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
                    0,
                    null,
                    $secondaryTaxCode,
                );

                $override = $line['tax_override_cents'] ?? null;
                $taxCents = $override !== null ? (int) $override : $totals['tax_cents'];

                $secondaryOverride = $line['secondary_tax_override_cents'] ?? null;
                $secondaryTaxCents = $secondaryOverride !== null ? (int) $secondaryOverride : $totals['secondary_tax_cents'];

                $bill->lines()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_discount_cents' => $totals['discount_cents'],
                    'line_discount_pct' => $line['line_discount_pct'] ?? null,
                    'tax_code_id' => $taxCode?->id,
                    'secondary_tax_code_id' => $secondaryTaxCode?->id,
                    'line_subtotal_cents' => $totals['subtotal_cents'],
                    'line_tax_cents' => $taxCents,
                    'tax_override_cents' => $override !== null ? (int) $override : null,
                    'secondary_tax_cents' => $secondaryTaxCents,
                    'secondary_tax_override_cents' => $secondaryOverride !== null ? (int) $secondaryOverride : null,
                    'line_total_cents' => $totals['subtotal_cents'] + $taxCents + $secondaryTaxCents,
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            $bill->refresh();
            $bill->recalculateTotals();

            return $bill;
        });
    }

    /**
     * The bill's currency: an explicit override, else the vendor's currency.
     * Null for the home currency.
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
    protected function resolveDueDate(array $data, CarbonImmutable $billDate): CarbonImmutable
    {
        if (! empty($data['due_date'])) {
            return CarbonImmutable::parse($data['due_date']);
        }

        if (! empty($data['terms_id'])) {
            $term = PaymentTerm::find($data['terms_id']);
            if ($term) {
                return $term->dueDateFrom($billDate);
            }
        }

        return $billDate;
    }
}
