<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TaxCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxCode
 */
class TaxCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            // Whole rates stay integers (backward compatible); fractional rates
            // such as QST's 997.5 are exposed with their decimals.
            'rate_basis_points' => (float) $this->rate_basis_points === (float) (int) $this->rate_basis_points
                ? (int) $this->rate_basis_points
                : round((float) $this->rate_basis_points, 3),
            'agency_id' => $this->agency_id,
            'applies_to' => $this->applies_to?->value,
            'is_recoverable' => (bool) $this->is_recoverable,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
