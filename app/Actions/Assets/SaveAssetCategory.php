<?php

namespace App\Actions\Assets;

use App\Models\AssetCategory;

/**
 * Creates or updates an asset category — a grouping of fixed assets with
 * default GL accounts that pre-fill on new asset records. Shared by the
 * Livewire settings page and the API.
 *
 * Expected $data shape:
 *   name: string  description: ?string
 *   default_asset_account_id: ?int
 *   default_accumulated_depreciation_account_id: ?int
 *   default_depreciation_expense_account_id: ?int
 *   default_useful_life_months: ?int
 *   is_active: ?bool
 */
final class SaveAssetCategory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?AssetCategory $category = null): AssetCategory
    {
        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_asset_account_id' => $data['default_asset_account_id'] ?? null,
            'default_accumulated_depreciation_account_id' => $data['default_accumulated_depreciation_account_id'] ?? null,
            'default_depreciation_expense_account_id' => $data['default_depreciation_expense_account_id'] ?? null,
            'default_useful_life_months' => $data['default_useful_life_months'] ?? null,
        ];

        if (array_key_exists('cca_class', $data)) {
            $attributes['cca_class'] = $data['cca_class'] ?: null;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($category && $category->exists) {
            $category->update($attributes);

            return $category;
        }

        return AssetCategory::create($attributes + [
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}
