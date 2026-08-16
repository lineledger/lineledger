<?php

namespace App\Actions\Sales;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * Converts an estimate into a Sales Order, links the two records, and marks the
 * estimate Converted. Reuses {@see SaveSalesOrder} so numbering, tax recompute,
 * and line creation stay in one place. The estimate can convert to either an
 * invoice or a sales order, not both — converting is a one-way, terminal step.
 */
final class ConvertEstimateToSalesOrder
{
    public function __construct(protected SaveSalesOrder $saveSalesOrder) {}

    public function handle(Estimate $estimate): SalesOrder
    {
        if (! $estimate->effectiveStatus()->canConvert()) {
            throw new \RuntimeException(__('This estimate cannot be converted.'));
        }

        return DB::transaction(function () use ($estimate): SalesOrder {
            $estimate->loadMissing('lines');

            $salesOrder = $this->saveSalesOrder->handle([
                'contact_id' => $estimate->contact_id,
                'order_no' => null,
                'order_date' => app('current_company')->currentDateTime()->toDateString(),
                'expected_date' => null,
                'terms_id' => $estimate->terms_id,
                'memo' => $estimate->memo,
                'customer_message' => $estimate->customer_message,
                'sales_rep_id' => $estimate->sales_rep_id,
                'customer_po' => $estimate->customer_po,
                'lines' => $estimate->lines->map(fn ($line): array => [
                    'item_id' => $line->item_id,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'service_date' => $line->service_date,
                    'quantity' => $line->quantity,
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'line_discount_cents' => (int) $line->line_discount_cents,
                    'line_discount_pct' => $line->line_discount_pct,
                    'tax_code_id' => $line->tax_code_id,
                    'secondary_tax_code_id' => $line->secondary_tax_code_id,
                    'class_id' => $line->class_id,
                    'location_id' => $line->location_id,
                ])->all(),
            ]);

            $estimate->forceFill([
                'status' => EstimateStatus::Converted,
                'converted_sales_order_id' => $salesOrder->id,
            ])->save();

            return $salesOrder;
        });
    }
}
