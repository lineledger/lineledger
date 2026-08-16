<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\CcaClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'name', 'description',
    'default_asset_account_id',
    'default_accumulated_depreciation_account_id',
    'default_depreciation_expense_account_id',
    'default_useful_life_months',
    'cca_class',
    'is_active',
])]
class AssetCategory extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function defaultAssetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_asset_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function defaultAccumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_accumulated_depreciation_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function defaultDepreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_depreciation_expense_account_id')->withoutGlobalScopes();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_useful_life_months' => 'integer',
            'cca_class' => CcaClass::class,
        ];
    }
}
