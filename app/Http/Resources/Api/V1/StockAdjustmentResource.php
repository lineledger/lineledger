<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockAdjustment
 */
class StockAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_no' => $this->adjustment_no,
            'adjustment_date' => optional($this->adjustment_date)->toDateString(),
            'reason' => $this->reason?->value,
            'notes' => $this->notes,
            'journal_entry_id' => $this->journal_entry_id,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'qty_change' => (string) $line->qty_change,
                'unit_cost_cents' => (int) $line->unit_cost_cents,
                'notes' => $line->notes,
            ])->all()),
        ];
    }
}
