<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recognition event against a deferred grant — DR deferred liability / CR grant
 * revenue. The GL-bearing ledger behind {@see Grant::recognized_to_date_cents}.
 */
#[Fillable([
    'company_id', 'grant_id', 'recognition_date', 'amount_cents', 'memo', 'journal_entry_id', 'voided_at',
])]
class GrantRecognition extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Grant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recognition_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'voided_at' => 'datetime',
        ];
    }
}
