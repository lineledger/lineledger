<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $fund_id
 */
#[Fillable([
    'journal_entry_id',
    'account_id',
    'debit_cents',
    'credit_cents',
    'currency_code',
    'fx_rate',
    'foreign_debit_cents',
    'foreign_credit_cents',
    'memo',
    'contact_id',
    'tax_code_id',
    'line_order',
    'class_id',
    'location_id',
    'fund_id',
    'cleared_at',
    'bank_reconciliation_id',
    'is_posted',
    'entry_date',
])]
class JournalLine extends Model
{
    /**
     * Seed the denormalised posting state from the parent entry at insert time so
     * that lines attached to an already-posted entry (e.g. fabricated directly in
     * tests) are immediately balance-correct. For the normal draft → post() flow
     * the entry is still a draft here; {@see JournalEntry} propagates the flip to
     * is_posted (and any entry_date edit) to existing lines via its own saved hook.
     */
    protected static function booted(): void
    {
        static::creating(function (JournalLine $line): void {
            if ($line->journal_entry_id === null) {
                return;
            }

            $entry = $line->relationLoaded('journalEntry')
                ? $line->getRelation('journalEntry')
                : JournalEntry::withoutGlobalScopes()->find($line->journal_entry_id);

            if ($entry !== null) {
                $line->is_posted = $entry->is_posted;
                $line->entry_date = $entry->entry_date?->toDateString();
            }
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
            'foreign_debit_cents' => 'integer',
            'foreign_credit_cents' => 'integer',
            'cleared_at' => 'datetime',
            'is_posted' => 'boolean',
            'entry_date' => 'date:Y-m-d',
        ];
    }
}
