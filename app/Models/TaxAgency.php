<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxReturnStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'registration_number', 'payable_account_id', 'is_active'])]
class TaxAgency extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    /**
     * @return HasMany<TaxCode, $this>
     */
    public function taxCodes(): HasMany
    {
        return $this->hasMany(TaxCode::class, 'agency_id');
    }

    /**
     * @return HasMany<TaxReturn, $this>
     */
    public function taxReturns(): HasMany
    {
        return $this->hasMany(TaxReturn::class);
    }

    /**
     * Whether any filed tax return for this agency covers the given date.
     */
    public function isFiledFor(CarbonInterface $date): bool
    {
        return $this->taxReturns()
            ->where('status', TaxReturnStatus::Filed->value)
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
