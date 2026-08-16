<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\BankReconciliationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'company_id',
    'account_id',
    'statement_date',
    'beginning_balance_cents',
    'ending_balance_cents',
    'service_charge_cents',
    'service_charge_date',
    'service_charge_account_id',
    'service_charge_entry_id',
    'interest_earned_cents',
    'interest_earned_date',
    'interest_earned_account_id',
    'interest_earned_entry_id',
    'status',
    'marked_line_ids',
    'completed_at',
    'completed_by_user_id',
])]
class BankReconciliation extends Model
{
    use BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        // Cancelling or undoing a reconciliation hard-deletes the row; purge its
        // attachment blobs + rows so nothing is orphaned in storage.
        static::deleting(function (self $rec): void {
            foreach ($rec->attachments()->get() as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->delete();
            }
        });
    }

    /**
     * Statements and other supporting files attached to this reconciliation.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function serviceChargeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'service_charge_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function interestEarnedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_earned_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function serviceChargeEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'service_charge_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function interestEarnedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'interest_earned_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', BankReconciliationStatus::Completed->value);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', BankReconciliationStatus::InProgress->value);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function isCompleted(): bool
    {
        return $this->status === BankReconciliationStatus::Completed;
    }

    public function isInProgress(): bool
    {
        return $this->status === BankReconciliationStatus::InProgress;
    }

    /**
     * @return array<int, int>
     */
    public function markedLineIds(): array
    {
        return array_values(array_map('intval', $this->marked_line_ids ?? []));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement_date' => 'date:Y-m-d',
            'service_charge_date' => 'date:Y-m-d',
            'interest_earned_date' => 'date:Y-m-d',
            'beginning_balance_cents' => 'integer',
            'ending_balance_cents' => 'integer',
            'service_charge_cents' => 'integer',
            'interest_earned_cents' => 'integer',
            'status' => BankReconciliationStatus::class,
            'marked_line_ids' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
