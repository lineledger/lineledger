<?php

namespace App\Actions\Sales;

use App\Enums\CreditMemoStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\TaxCode;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a credit memo header and its line items, recalculating
 * totals. Shared by the Livewire form and the API. Does NOT post — the caller
 * decides whether to hand the result to CreditMemoPoster.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:       int
 *   sales_rep_id:     ?int     (employee credited with the sale)
 *   credit_memo_no:   ?string  (null → auto-generated)
 *   credit_memo_date: string
 *   memo:             ?string
 *   customer_message: ?string
 *   lines: array<int, array{
 *     item_id: ?int, account_id: int, description: ?string, service_date: ?string,
 *     quantity: string|int|float, unit_price_cents: int,
 *     line_discount_cents: ?int, line_discount_pct: ?string, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int
 *   }>
 */
final class SaveCreditMemo
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxCalculator $taxCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?CreditMemo $memo = null): CreditMemo
    {
        return DB::transaction(function () use ($data, $memo): CreditMemo {
            $company = app('current_company');

            $header = [
                'contact_id' => $data['contact_id'],
                'sales_rep_id' => $data['sales_rep_id'] ?? null,
                'credit_memo_date' => CarbonImmutable::parse($data['credit_memo_date'])->toDateString(),
                'memo' => $data['memo'] ?? null,
                'customer_message' => $data['customer_message'] ?? null,
            ];

            if ($memo && $memo->exists) {
                $memo->update($header);
            } else {
                $memo = CreditMemo::create($header + [
                    'credit_memo_no' => $data['credit_memo_no']
                        ?? $this->numbers->next($company, CreditMemo::class, 'credit_memo_no', 'CM'),
                    'status' => CreditMemoStatus::Draft,
                    'currency_code' => $this->resolveCurrencyCode($company, $data),
                ]);
            }

            $memo->lines()->delete();

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

                $memo->lines()->create([
                    'description' => $line['description'] ?? null,
                    'service_date' => ! empty($line['service_date'])
                        ? CarbonImmutable::parse($line['service_date'])->toDateString()
                        : null,
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_discount_cents' => $totals['discount_cents'],
                    'line_discount_pct' => $line['line_discount_pct'] ?? null,
                    'account_id' => $line['account_id'],
                    'item_id' => $line['item_id'] ?? null,
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

            $memo->refresh();
            $memo->recalculateTotals();

            return $memo;
        });
    }

    /**
     * The memo's currency: an explicit override, else the contact's currency.
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
}
