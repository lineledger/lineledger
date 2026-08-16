<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Bill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bill
 */
class BillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_no' => $this->bill_no,
            'bill_type' => $this->bill_type?->value,
            'contact_id' => $this->contact_id,
            'vendor_reference' => $this->vendor_reference,
            'bill_date' => optional($this->bill_date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'terms_id' => $this->terms_id,
            'status' => $this->status?->value,
            'subtotal_cents' => (int) $this->subtotal_cents,
            'tax_cents' => (int) $this->tax_cents,
            'total_cents' => (int) $this->total_cents,
            'amount_paid_cents' => (int) $this->amount_paid_cents,
            'memo' => $this->memo,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'account_id' => $line->account_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price_cents' => (int) $line->unit_price_cents,
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
