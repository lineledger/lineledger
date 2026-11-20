<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id',
    'entry_no',
    'entry_date',
    'memo',
    'source_type',
    'source_id',
    'source_external_id',
    'recurring_journal_entry_id',
    'is_posted',
    'posted_at',
    'posted_by_user_id',
    'voided_at',
    'voided_by_user_id',
    'reversed_by_entry_id',
    'reverses_entry_id',
])]
class JournalEntry extends Model
{
    use BelongsToCompany;

    /**
     * Source documents whose journal entries may still be edited from the
     * general journal — header only (date, number, memo). Their lines stay
     * locked because the source keeps its own copy of the amounts. Today that
     * is just the bank-reconciliation service-charge / interest adjustments,
     * which have no editable form of their own once posted.
     *
     * @var list<class-string>
     */
    public const JOURNAL_EDITABLE_SOURCES = [BankReconciliation::class];

    /**
     * Keep the posting state denormalised onto journal_lines in sync. Lines inherit
     * is_posted / entry_date at creation (see {@see JournalLine}); this propagates
     * any later change — chiefly the draft → posted flip in JournalPoster, and the
     * rare entry_date edit on a draft — to lines that already exist, in one UPDATE.
     */
    protected static function booted(): void
    {
        static::saved(function (JournalEntry $entry): void {
            if ($entry->wasChanged('is_posted') || $entry->wasChanged('entry_date')) {
                JournalLine::query()
                    ->where('journal_entry_id', $entry->id)
                    ->update([
                        'is_posted' => $entry->is_posted,
                        'entry_date' => $entry->entry_date?->toDateString(),
                    ]);
            }
        });
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_order');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<RecurringJournalEntry, $this>
     */
    public function recurringJournalEntry(): BelongsTo
    {
        return $this->belongsTo(RecurringJournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isPosted(): bool
    {
        return (bool) $this->is_posted;
    }

    /**
     * Whether a void wrote this entry: it reverses an entry that was voided and
     * that names this entry as its reversal. JournalPoster::void() gives the
     * reversal no source of its own, so without this check it would look like a
     * manual entry. It belongs to that void (of a document or of a manual
     * entry), and the general journal must not void, reverse, rewrite or delete
     * it on its own.
     *
     * A draft accrual reversal (Reverse) also carries reverses_entry_id, but its
     * original is not voided, so it stays an ordinary manual entry.
     */
    public function isVoidReversal(): bool
    {
        if ($this->reverses_entry_id === null) {
            return false;
        }

        $original = $this->reverses;

        return $original !== null
            && $original->isVoided()
            && (int) $original->reversed_by_entry_id === (int) $this->id;
    }

    /**
     * A manual entry the general journal fully manages (edit, void, reverse,
     * delete): no source document, and not the reversal a void wrote. The one
     * gate the journal page, the journal form and the API all ask.
     */
    public function isManualEntry(): bool
    {
        return $this->source_type === null && ! $this->isVoidReversal();
    }

    /**
     * Whether the general journal may edit this entry at all: manual entries,
     * plus the source-linked types listed in {@see JOURNAL_EDITABLE_SOURCES}.
     */
    public function isEditableInJournal(): bool
    {
        return $this->isManualEntry()
            || in_array($this->source_type, self::JOURNAL_EDITABLE_SOURCES, true);
    }

    /**
     * Whether only the header (date, number, memo) may be edited from the
     * journal — the lines belong to the originating document.
     */
    public function hasLockedLines(): bool
    {
        return $this->source_type !== null
            && in_array($this->source_type, self::JOURNAL_EDITABLE_SOURCES, true);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function totalDebitsCents(): int
    {
        return (int) $this->lines->sum('debit_cents');
    }

    public function totalCreditsCents(): int
    {
        return (int) $this->lines->sum('credit_cents');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitsCents() === $this->totalCreditsCents()
            && $this->totalDebitsCents() > 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
