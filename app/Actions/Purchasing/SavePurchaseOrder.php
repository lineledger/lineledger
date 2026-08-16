<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\TaxCode;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a purchase order header and its line items, recalculating
 * totals. Purchase orders are non-posting (no GL hand-off) — receiving happens
 * later by generating bills via {@see FulfillPurchaseOrder}. This is the only
 * write path for a purchase order's data.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:      int
 *   po_no:           ?string  (null → auto-generated)
 *   po_date:         string
 *   expected_date:   ?string
 *   ship_to:         ?string
 *   terms_id:        ?int
 *   memo:            ?string
 *   vendor_message:  ?string
 *   lines: array<int, array{
 *     item_id: ?int, account_id: int, description: ?string,
 *     quantity: string|int|float, unit_price_cents: int,
 *     line_discount_cents: ?int, line_discount_pct: ?string, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int
 *   }>
 */
final class SavePurchaseOrder
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxCalculator $taxCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?PurchaseOrder $purchaseOrder = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $purchaseOrder): PurchaseOrder {
            $company = app('current_company');
            $poDate = CarbonImmutable::parse($data['po_date']);

            $header = [
                'contact_id' => $data['contact_id'],
                'po_date' => $poDate->toDateString(),
                'expected_date' => ! empty($data['expected_date'])
                    ? CarbonImmutable::parse($data['expected_date'])->toDateString()
                    : null,
                'ship_to' => $data['ship_to'] ?? null,
                'terms_id' => $data['terms_id'] ?? null,
                'memo' => $data['memo'] ?? null,
                'vendor_message' => $data['vendor_message'] ?? null,
            ];

            if ($purchaseOrder && $purchaseOrder->exists) {
                $purchaseOrder->update($header);
            } else {
                $purchaseOrder = PurchaseOrder::create($header + [
                    'po_no' => $data['po_no']
                        ?? $this->numbers->next($company, PurchaseOrder::class, 'po_no', 'PO'),
                    'status' => PurchaseOrderStatus::Open,
                ]);
            }

            $purchaseOrder->lines()->delete();

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

                $purchaseOrder->lines()->create([
                    'description' => $line['description'] ?? null,
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

            $purchaseOrder->refresh();
            $purchaseOrder->recalculateTotals();

            return $purchaseOrder;
        });
    }
}
