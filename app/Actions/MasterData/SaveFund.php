<?php

namespace App\Actions\MasterData;

use App\Enums\FundType;
use App\Models\Fund;

/**
 * Creates or updates a fund (ASNPO restricted fund method). Shared by the Livewire
 * settings page and the API.
 *
 * Expected $data shape:
 *   name:      string
 *   fund_type: ?string
 *   is_active: ?bool
 */
final class SaveFund
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Fund $fund = null): Fund
    {
        $attributes = ['name' => $data['name']];

        if (array_key_exists('fund_type', $data)) {
            $attributes['fund_type'] = $data['fund_type'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($fund && $fund->exists) {
            $fund->update($attributes);

            return $fund;
        }

        return Fund::create($attributes + [
            'is_active' => $data['is_active'] ?? true,
            'fund_type' => $data['fund_type'] ?? FundType::Restricted->value,
        ]);
    }
}
