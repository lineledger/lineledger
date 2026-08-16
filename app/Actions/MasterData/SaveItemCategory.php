<?php

namespace App\Actions\MasterData;

use App\Models\ItemCategory;

/**
 * Creates or updates an item category (QuickBooks "Category"). Shared by the
 * Livewire settings page and the API.
 *
 * Expected $data shape:
 *   name:      string
 *   parent_id: ?int
 *   is_active: ?bool
 */
final class SaveItemCategory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?ItemCategory $category = null): ItemCategory
    {
        $attributes = [
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
        ];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($category && $category->exists) {
            $category->update($attributes);

            return $category;
        }

        return ItemCategory::create($attributes + ['is_active' => $data['is_active'] ?? true]);
    }
}
