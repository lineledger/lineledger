<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\Activity;
use App\Models\Account;
use App\Support\Access\ApiViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
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
            'type' => $this->type?->value,
            'subtype' => $this->subtype?->value,
            'normal_balance' => $this->normal_balance?->value,
            'currency_code' => $this->currency_code,
            'parent_id' => $this->parent_id,
            'default_tax_code_id' => $this->default_tax_code_id,
            'description' => $this->description,
            'cash_flow_activity' => $this->cash_flow_activity?->value,
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            // Null when the key's creator may not see account balances.
            'balance_cents' => ApiViewer::canViewBalances(Activity::Accounts) ? (int) $this->balance_cents : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
