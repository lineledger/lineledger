<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxReturnAdjustmentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual line on a tax return: a correction to the tax collected or the
 * input tax credits, or another amount (a collector's commission, a fee, a
 * balance carried from an earlier period).
 *
 * Coded to the agency's payable account, it only changes the return — the
 * amount is already in the ledger. Coded to any other account, filing posts it
 * against the payable account (see TaxReturnAdjustmentPoster).
 *
 * @property TaxReturnAdjustmentKind $kind
 */
#[Fillable([
    'company_id', 'tax_return_id', 'kind', 'account_id',
    'amount_cents', 'memo', 'line_order',
])]
class TaxReturnAdjustment extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<TaxReturn, $this>
     */
    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The change this adjustment makes to the return's net owing.
     */
    public function effectOnNet(): int
    {
        return $this->kind->effectOnNet((int) $this->amount_cents);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaxReturnAdjustmentKind::class,
            'amount_cents' => 'integer',
            'line_order' => 'integer',
        ];
    }
}
