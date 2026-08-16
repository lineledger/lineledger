<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TaxAgency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxAgency
 */
class TaxAgencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'registration_number' => $this->registration_number,
            'payable_account_id' => $this->payable_account_id,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
