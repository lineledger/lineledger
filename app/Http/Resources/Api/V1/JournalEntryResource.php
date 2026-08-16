<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_no' => $this->entry_no,
            'entry_date' => optional($this->entry_date)->toDateString(),
            'memo' => $this->memo,
            'is_posted' => (bool) $this->is_posted,
            'status' => $this->statusLabel(),
            'total_debits_cents' => $this->totalDebitsCents(),
            'total_credits_cents' => $this->totalCreditsCents(),
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            'reversed_by_entry_id' => $this->reversed_by_entry_id,
            'reverses_entry_id' => $this->reverses_entry_id,
            'lines' => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'contact_id' => $line->contact_id,
                'tax_code_id' => $line->tax_code_id,
                'debit_cents' => (int) $line->debit_cents,
                'credit_cents' => (int) $line->credit_cents,
                'memo' => $line->memo,
            ])->all(),
        ];
    }

    protected function statusLabel(): string
    {
        if ($this->voided_at !== null) {
            return 'void';
        }

        return $this->is_posted ? 'posted' : 'draft';
    }
}
