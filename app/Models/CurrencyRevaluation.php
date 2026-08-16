<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period-end Home Currency Adjustment run: the journal entry that revalues open
 * foreign balances at a closing rate, plus the auto-reversing entry dated the
 * next day. rate_snapshot records the closing rate used per currency.
 */
#[Fillable([
    'company_id',
    'as_of_date',
    'journal_entry_id',
    'reversal_entry_id',
    'rate_snapshot',
])]
class CurrencyRevaluation extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date:Y-m-d',
            'rate_snapshot' => 'array',
        ];
    }
}
