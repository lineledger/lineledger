<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_no' => $this->asset_no,
            'name' => $this->name,
            'description' => $this->description,
            'asset_category_id' => $this->asset_category_id,
            'asset_account_id' => $this->asset_account_id,
            'accumulated_depreciation_account_id' => $this->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $this->depreciation_expense_account_id,
            'serial_number' => $this->serial_number,
            'location' => $this->location,
            'acquired_date' => optional($this->acquired_date)->toDateString(),
            'in_service_date' => optional($this->in_service_date)->toDateString(),
            'cost_cents' => (int) $this->cost_cents,
            'salvage_value_cents' => (int) $this->salvage_value_cents,
            'useful_life_months' => $this->useful_life_months,
            'status' => $this->status?->value,
            'disposed_at' => optional($this->disposed_at)->toDateString(),
            'disposal_notes' => $this->disposal_notes,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
