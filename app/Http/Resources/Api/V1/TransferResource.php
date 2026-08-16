<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transfer
 */
class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_no' => $this->transfer_no,
            'from_account_id' => $this->from_account_id,
            'to_account_id' => $this->to_account_id,
            'transfer_date' => optional($this->transfer_date)->toDateString(),
            'memo' => $this->memo,
            'from_amount_cents' => (int) $this->from_amount_cents,
            'to_amount_cents' => (int) $this->to_amount_cents,
            'from_currency_code' => $this->from_currency_code,
            'to_currency_code' => $this->to_currency_code,
            'from_fx_rate' => $this->from_fx_rate,
            'to_fx_rate' => $this->to_fx_rate,
            'home_amount_cents' => $this->home_amount_cents !== null ? (int) $this->home_amount_cents : null,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
