<?php

namespace App\Actions\MasterData;

use App\Enums\RecurrenceFrequency;
use App\Models\MembershipLevel;

/**
 * Creates or updates a membership level (tier). Shared by the Livewire settings
 * page and the API.
 *
 * Expected $data shape:
 *   name:                string
 *   default_dues_cents:  ?int
 *   billing_frequency:   ?string
 *   revenue_account_id:  ?int
 *   default_terms_id:    ?int
 *   default_tax_code_id: ?int
 *   is_active:           ?bool
 */
final class SaveMembershipLevel
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?MembershipLevel $level = null): MembershipLevel
    {
        $attributes = ['name' => $data['name']];

        foreach (['default_dues_cents', 'billing_frequency', 'revenue_account_id', 'default_terms_id', 'default_tax_code_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($level && $level->exists) {
            $level->update($attributes);

            return $level;
        }

        return MembershipLevel::create($attributes + [
            'default_dues_cents' => (int) ($data['default_dues_cents'] ?? 0),
            'billing_frequency' => $data['billing_frequency'] ?? RecurrenceFrequency::Annual->value,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}
