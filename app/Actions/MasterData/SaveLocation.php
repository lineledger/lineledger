<?php

namespace App\Actions\MasterData;

use App\Models\Location;

/**
 * Creates or updates a location (QuickBooks "Location"). Shared by the Livewire
 * settings page and the API.
 *
 * Expected $data shape:
 *   name:      string
 *   is_active: ?bool
 */
final class SaveLocation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Location $location = null): Location
    {
        $attributes = ['name' => $data['name']];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($location && $location->exists) {
            $location->update($attributes);

            return $location;
        }

        return Location::create($attributes + ['is_active' => $data['is_active'] ?? true]);
    }
}
