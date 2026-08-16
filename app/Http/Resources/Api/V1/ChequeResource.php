<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Cheque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cheque
 */
class ChequeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cheque_no' => $this->cheque_no,
            'bank_account_id' => $this->bank_account_id,
            'cheque_date' => optional($this->cheque_date)->toDateString(),
            'payee_contact_id' => $this->payee_contact_id,
            'payee_name' => $this->payee_name,
            'credit_memo_id' => $this->credit_memo_id,
            'memo' => $this->memo,
            'amount_cents' => (int) $this->amount_cents,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'description' => $line->description,
                'amount_cents' => (int) $line->amount_cents,
                'tax_code_id' => $line->tax_code_id,
                'secondary_tax_code_id' => $line->secondary_tax_code_id,
                'tax_cents' => (int) $line->tax_cents,
                'secondary_tax_cents' => (int) $line->secondary_tax_cents,
            ])->all(),
        ];
    }
}
