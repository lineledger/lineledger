<?php

namespace App\Actions\MasterData;

use App\Enums\TaxAppliesTo;
use App\Models\TaxCode;

/**
 * Creates or updates a sales-tax code. Shared by the Livewire tax-codes page
 * and the API. The rate is supplied already normalized to basis points
 * (1% = 100 bps) so the Action stays framework-agnostic; callers convert.
 *
 * Expected $data shape:
 *   code:             string
 *   name:             string
 *   rate_basis_points: int|float  (basis points, up to 3 decimals — e.g. 997.5 for QST)
 *   agency_id:        ?int
 *   applies_to:       string  (TaxAppliesTo value)
 *   is_recoverable:   ?bool
 *   is_active:        ?bool
 */
final class SaveTaxCode
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TaxCode $taxCode = null): TaxCode
    {
        $attributes = [
            'code' => $data['code'],
            'name' => $data['name'],
            'rate_basis_points' => round((float) $data['rate_basis_points'], 3),
            'agency_id' => $data['agency_id'] ?? null,
            'applies_to' => TaxAppliesTo::from($data['applies_to']),
        ];

        if (array_key_exists('is_recoverable', $data)) {
            $attributes['is_recoverable'] = (bool) $data['is_recoverable'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($taxCode && $taxCode->exists) {
            $taxCode->update($attributes);

            return $taxCode;
        }

        return TaxCode::create($attributes + [
            'is_recoverable' => $data['is_recoverable'] ?? true,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }
}
