<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'from_account_id', 'to_account_id', 'transfer_no', 'transfer_date',
    'memo', 'from_amount_cents', 'to_amount_cents', 'from_currency_code', 'to_currency_code',
    'from_fx_rate', 'to_fx_rate', 'home_amount_cents', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class Transfer extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === TransferStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === TransferStatus::Posted;
    }

    public function isVoid(): bool
    {
        return $this->status === TransferStatus::Void;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transfer_date' => 'date:Y-m-d',
            'status' => TransferStatus::class,
            'from_amount_cents' => 'integer',
            'to_amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
