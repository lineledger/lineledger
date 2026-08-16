<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TaxReturnPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxReturnPayment
 */
class TaxReturnPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_return_id' => $this->tax_return_id,
            'payment_no' => $this->payment_no,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'direction' => $this->direction?->value,
            'status' => $this->status?->value,
            'bank_account_id' => $this->bank_account_id,
            'payment_method_id' => $this->payment_method_id,
            'reference' => $this->reference,
            'net_amount_cents' => (int) $this->net_amount_cents,
            'penalty_cents' => (int) $this->penalty_cents,
            'penalty_account_id' => $this->penalty_account_id,
            'interest_cents' => (int) $this->interest_cents,
            'interest_account_id' => $this->interest_account_id,
            'commission_cents' => (int) $this->commission_cents,
            'commission_account_id' => $this->commission_account_id,
            'total_cents' => (int) $this->total_cents,
            'notes' => $this->notes,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
