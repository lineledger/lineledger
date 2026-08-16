<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetCategory
 */
class AssetCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'default_asset_account_id' => $this->default_asset_account_id,
            'default_accumulated_depreciation_account_id' => $this->default_accumulated_depreciation_account_id,
            'default_depreciation_expense_account_id' => $this->default_depreciation_expense_account_id,
            'default_useful_life_months' => $this->default_useful_life_months,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
