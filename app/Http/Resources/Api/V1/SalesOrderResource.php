<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesOrder
 */
class SalesOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'contact_id' => $this->contact_id,
            'order_date' => optional($this->order_date)->toDateString(),
            'expected_date' => optional($this->expected_date)->toDateString(),
            'terms_id' => $this->terms_id,
            'status' => $this->effectiveStatus()->value,
            'subtotal_cents' => (int) $this->subtotal_cents,
            'tax_cents' => (int) $this->tax_cents,
            'total_cents' => (int) $this->total_cents,
            'memo' => $this->memo,
            'customer_message' => $this->customer_message,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'qty_ordered' => (string) $line->quantity,
                'qty_invoiced' => number_format($line->qtyInvoiced(), 4, '.', ''),
                'qty_backordered' => number_format($line->qtyBackordered(), 4, '.', ''),
                'unit_price_cents' => (int) $line->unit_price_cents,
                'account_id' => $line->account_id,
                'item_id' => $line->item_id,
                'tax_code_id' => $line->tax_code_id,
                'secondary_tax_code_id' => $line->secondary_tax_code_id,
                'line_subtotal_cents' => (int) $line->line_subtotal_cents,
                'line_tax_cents' => (int) $line->line_tax_cents,
                'secondary_tax_cents' => (int) $line->secondary_tax_cents,
                'line_total_cents' => (int) $line->line_total_cents,
            ])->all(),
        ];
    }
}
