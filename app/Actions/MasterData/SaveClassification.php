<?php

namespace App\Actions\MasterData;

use App\Models\Classification;

/**
 * Creates or updates a classification (QuickBooks "Class"). Shared by the
 * Livewire settings page and the API.
 *
 * Expected $data shape:
 *   name:      string
 *   is_active: ?bool
 */
final class SaveClassification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Classification $classification = null): Classification
    {
        $attributes = ['name' => $data['name']];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($classification && $classification->exists) {
            $classification->update($attributes);

            return $classification;
        }

        return Classification::create($attributes + ['is_active' => $data['is_active'] ?? true]);
    }
}
