<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentTerm
 */
class PaymentTermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'days' => (int) $this->days,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
