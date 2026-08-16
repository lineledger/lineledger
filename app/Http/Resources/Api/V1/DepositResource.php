<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deposit
 */
class DepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deposit_no' => $this->deposit_no,
            'bank_account_id' => $this->bank_account_id,
            'deposit_date' => optional($this->deposit_date)->toDateString(),
            'memo' => $this->memo,
            'amount_cents' => (int) $this->amount_cents,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'customer_receipt_id' => $line->customer_receipt_id,
                'account_id' => $line->account_id,
                'contact_id' => $line->contact_id,
                'description' => $line->description,
                'amount_cents' => (int) $line->amount_cents,
            ])->all(),
        ];
    }
}
