<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\GrantStatus;
use Database\Factories\GrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A grant from a funder. Posting the award books the deferred liability (ASNPO
 * deferral method) or grant revenue directly (restricted-fund method / unrestricted).
 * Deferred grants recognize revenue over time through {@see GrantRecognition} rows.
 */
#[Fillable([
    'company_id', 'funder_contact_id', 'grant_no', 'name', 'status',
    'award_amount_cents', 'currency_code', 'is_restricted', 'fund_id',
    'period_start', 'period_end', 'receivable_on_award',
    'deposit_to_account_id', 'deferred_account_id', 'revenue_account_id',
    'recognition_method', 'recognized_to_date_cents', 'award_journal_entry_id', 'notes',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
])]
class Grant extends Model
{
    /** @use HasFactory<GrantFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function funder(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'funder_contact_id');
    }

    /**
     * @return BelongsTo<Fund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function awardJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'award_journal_entry_id');
    }

    /**
     * @return HasMany<GrantRecognition, $this>
     */
    public function recognitions(): HasMany
    {
        return $this->hasMany(GrantRecognition::class);
    }

    public function isDraft(): bool
    {
        return $this->status === GrantStatus::Draft;
    }

    /**
     * Amount still sitting in the deferred liability, awaiting recognition.
     */
    public function deferredBalanceCents(): int
    {
        return max(0, $this->award_amount_cents - $this->recognized_to_date_cents);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GrantStatus::class,
            'award_amount_cents' => 'integer',
            'is_restricted' => 'boolean',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'receivable_on_award' => 'boolean',
            'recognized_to_date_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
