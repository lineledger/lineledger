<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'bank_account_id', 'deposit_no', 'deposit_date',
    'memo', 'is_opening_balance', 'amount_cents', 'currency_code', 'fx_rate', 'home_amount_cents', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class Deposit extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return HasMany<DepositLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DepositLine::class)->orderBy('line_order');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function recalculateAmount(): void
    {
        $this->loadMissing('lines');

        $this->forceFill(['amount_cents' => (int) $this->lines->sum('amount_cents')])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deposit_date' => 'date:Y-m-d',
            'status' => DepositStatus::class,
            'is_opening_balance' => 'boolean',
            'amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
