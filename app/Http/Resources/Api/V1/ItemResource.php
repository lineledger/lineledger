<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'income_account_id' => $this->income_account_id,
            'expense_account_id' => $this->expense_account_id,
            'default_tax_code_id' => $this->default_tax_code_id,
            'default_secondary_tax_code_id' => $this->default_secondary_tax_code_id,
            'default_price_cents' => (int) $this->default_price_cents,
            'track_inventory' => (bool) $this->track_inventory,
            'inventory_asset_account_id' => $this->inventory_asset_account_id,
            'cogs_account_id' => $this->cogs_account_id,
            'reorder_point' => $this->reorder_point !== null ? (string) $this->reorder_point : null,
            'qty_on_hand' => (string) $this->qty_on_hand_cached,
            'unit_cost_cents' => (int) $this->unit_cost_cents_cached,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
