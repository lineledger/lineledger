<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One draft-trial-balance target: what an account's balance SHOULD be at the
 * workspace's as-of date. Exactly one of debit_cents / credit_cents is set,
 * matching the row shape of a QuickBooks trial balance export.
 */
#[Fillable([
    'company_id', 'opening_balance_state_id', 'account_id',
    'debit_cents', 'credit_cents', 'updated_by_user_id',
])]
class OpeningBalanceRow extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<OpeningBalanceState, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceState::class, 'opening_balance_state_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Debit-positive signed home cents. */
    public function signedCents(): int
    {
        return (int) $this->debit_cents - (int) $this->credit_cents;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
        ];
    }
}
