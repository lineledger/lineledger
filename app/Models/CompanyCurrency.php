<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A currency a company transacts in. Exactly one row per company is the home
 * currency (is_home); each active foreign currency wires its own AR/AP control
 * accounts and may override the FX gain/loss account.
 */
#[Fillable([
    'company_id',
    'currency_code',
    'is_home',
    'is_active',
    'ar_account_id',
    'ap_account_id',
    'gain_loss_account_id',
])]
class CompanyCurrency extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function arAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ar_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function apAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ap_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function gainLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gain_loss_account_id')->withoutGlobalScopes();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
