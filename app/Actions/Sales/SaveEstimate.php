<?php

namespace App\Actions\Sales;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\TaxCode;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates an estimate header and its line items, recalculating totals.
 * Estimates are non-posting, so there is no GL hand-off — this is the only write
 * path for an estimate's data.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:       int
 *   sales_rep_id:     ?int     (employee credited with the sale)
 *   estimate_no:      ?string  (null → auto-generated)
 *   estimate_date:    string
 *   expires_on:       ?string
 *   customer_po:      ?string
 *   terms_id:         ?int
 *   memo:             ?string
 *   customer_message: ?string
 *   lines: array<int, array{
 *     item_id: ?int, account_id: int, description: ?string, service_date: ?string,
 *     quantity: string|int|float, unit_price_cents: int,
 *     line_discount_cents: ?int, line_discount_pct: ?string, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int
 *   }>
 */
final class SaveEstimate
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxCalculator $taxCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Estimate $estimate = null): Estimate
    {
        return DB::transaction(function () use ($data, $estimate): Estimate {
            $company = app('current_company');
            $estimateDate = CarbonImmutable::parse($data['estimate_date']);

            $header = [
                'contact_id' => $data['contact_id'],
                'sales_rep_id' => $data['sales_rep_id'] ?? null,
                'estimate_date' => $estimateDate->toDateString(),
                'expires_on' => ! empty($data['expires_on'])
                    ? CarbonImmutable::parse($data['expires_on'])->toDateString()
                    : null,
                'customer_po' => $data['customer_po'] ?? null,
                'terms_id' => $data['terms_id'] ?? null,
                'memo' => $data['memo'] ?? null,
                'customer_message' => $data['customer_message'] ?? null,
            ];

            if ($estimate && $estimate->exists) {
                $estimate->update($header);
            } else {
                $estimate = Estimate::create($header + [
                    'estimate_no' => $data['estimate_no']
                        ?? $this->numbers->next($company, Estimate::class, 'estimate_no', 'EST'),
                    'status' => EstimateStatus::Pending,
                ]);
            }

            $estimate->lines()->delete();

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

                $estimate->lines()->create([
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

            $estimate->refresh();
            $estimate->recalculateTotals();

            return $estimate;
        });
    }
}
