<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BillPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillPayment
 */
class BillPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_no' => $this->payment_no,
            'payment_type' => $this->payment_type?->value,
            'contact_id' => $this->contact_id,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'paid_from_account_id' => $this->paid_from_account_id,
            'payment_method_id' => $this->payment_method_id,
            'reference' => $this->reference,
            'amount_cents' => (int) $this->amount_cents,
            'memo' => $this->memo,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'applications' => $this->applications->map(fn ($app) => [
                'id' => $app->id,
                'bill_id' => $app->bill_id,
                'amount_cents' => (int) $app->amount_cents,
            ])->all(),
        ];
    }
}
