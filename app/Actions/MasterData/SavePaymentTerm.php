<?php

namespace App\Actions\MasterData;

use App\Models\PaymentTerm;

/**
 * Creates or updates a payment term. Shared by the Livewire settings page and
 * the API.
 *
 * Expected $data shape:
 *   name:      string
 *   days:      int
 *   is_active: ?bool
 */
final class SavePaymentTerm
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?PaymentTerm $term = null): PaymentTerm
    {
        $attributes = [
            'name' => $data['name'],
            'days' => (int) $data['days'],
        ];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($term && $term->exists) {
            $term->update($attributes);

            return $term;
        }

        return PaymentTerm::create($attributes + [
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}
