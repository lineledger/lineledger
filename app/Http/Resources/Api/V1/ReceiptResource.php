<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CustomerReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerReceipt
 */
class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'contact_id' => $this->contact_id,
            'receipt_date' => optional($this->receipt_date)->toDateString(),
            'deposit_to_account_id' => $this->deposit_to_account_id,
            'payment_method_id' => $this->payment_method_id,
            'reference' => $this->reference,
            'amount_cents' => (int) $this->amount_cents,
            // The part not yet applied to any invoice — the customer's credit
            // on this receipt. Applying it is a PUT/PATCH with the full
            // applications list (existing rows plus the new one).
            'unapplied_cents' => (int) $this->amount_cents - (int) $this->applications->sum('amount_cents'),
            'memo' => $this->memo,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'applications' => $this->applications->map(fn ($app) => [
                'id' => $app->id,
                'invoice_id' => $app->invoice_id,
                'amount_cents' => (int) $app->amount_cents,
            ])->all(),
        ];
    }
}
