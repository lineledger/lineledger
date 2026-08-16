<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TaxReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxReturn
 */
class TaxReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_return_no' => $this->tax_return_no,
            'tax_agency_id' => $this->tax_agency_id,
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'status' => $this->status?->value,
            'collected_cents' => (int) $this->collected_cents,
            'paid_cents' => (int) $this->paid_cents,
            'net_cents' => (int) $this->net_cents,
            'filing_reference' => $this->filing_reference,
            'notes' => $this->notes,
            'filed_at' => optional($this->filed_at)->toIso8601String(),
            'voided_at' => optional($this->voided_at)->toIso8601String(),
            'void_reason' => $this->void_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'bucket' => $line->bucket,
                'amount_cents' => (int) $line->amount_cents,
                'entry_no' => $line->entry_no,
                'entry_date' => optional($line->entry_date)->toDateString(),
                'journal_entry_id' => $line->journal_entry_id,
                'journal_line_id' => $line->journal_line_id,
                'source_type' => $line->source_type,
                'source_id' => $line->source_id,
                'doc_label' => $line->doc_label,
                'is_reversal' => (bool) $line->is_reversal,
            ])->all()),
        ];
    }
}
