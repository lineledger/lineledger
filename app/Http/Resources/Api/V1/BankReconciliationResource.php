<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BankReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankReconciliation
 */
class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'statement_date' => optional($this->statement_date)->toDateString(),
            'beginning_balance_cents' => (int) $this->beginning_balance_cents,
            'ending_balance_cents' => (int) $this->ending_balance_cents,
            'service_charge_cents' => (int) $this->service_charge_cents,
            'service_charge_date' => optional($this->service_charge_date)->toDateString(),
            'service_charge_account_id' => $this->service_charge_account_id,
            'service_charge_entry_id' => $this->service_charge_entry_id,
            'interest_earned_cents' => (int) $this->interest_earned_cents,
            'interest_earned_date' => optional($this->interest_earned_date)->toDateString(),
            'interest_earned_account_id' => $this->interest_earned_account_id,
            'interest_earned_entry_id' => $this->interest_earned_entry_id,
            'status' => $this->status?->value,
            'marked_line_ids' => $this->markedLineIds(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'completed_by_user_id' => $this->completed_by_user_id,
        ];
    }
}
