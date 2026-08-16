<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\AssetStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ?CarbonInterface $in_service_date
 * @property ?CarbonInterface $disposed_at
 */
#[Fillable([
    'company_id', 'asset_no', 'name', 'description', 'asset_category_id',
    'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id',
    'serial_number', 'location',
    'acquired_date', 'in_service_date', 'cost_cents', 'salvage_value_cents',
    'useful_life_months', 'auto_depreciate', 'status', 'disposed_at', 'disposal_notes',
    'source_type', 'source_id', 'notes', 'is_active',
])]
class Asset extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id')->withoutGlobalScopes();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return HasMany<AssetDepreciationEntry, $this>
     */
    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class)->orderBy('period');
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->where('status', AssetStatus::InService->value);
    }

    public function scopeDisposed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AssetStatus::Disposed->value,
            AssetStatus::Sold->value,
            AssetStatus::Lost->value,
        ]);
    }

    public function netCostCents(): int
    {
        return (int) $this->cost_cents - (int) $this->salvage_value_cents;
    }

    /**
     * Whether the asset's configuration supports automatic monthly book
     * depreciation: opted in, with an in-service date, a useful life of at
     * least one month, both depreciation accounts, and a positive depreciable
     * base. Liveness (is_active) is filtered by the generator's query — a
     * disposed asset can still back-fill months before its disposal month.
     */
    public function isAutoDepreciable(): bool
    {
        return (bool) $this->auto_depreciate
            && $this->in_service_date !== null
            && (int) $this->useful_life_months >= 1
            && $this->accumulated_depreciation_account_id !== null
            && $this->depreciation_expense_account_id !== null
            && $this->netCostCents() > 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_date' => 'date:Y-m-d',
            'in_service_date' => 'date:Y-m-d',
            'disposed_at' => 'date:Y-m-d',
            'status' => AssetStatus::class,
            'cost_cents' => 'integer',
            'salvage_value_cents' => 'integer',
            'useful_life_months' => 'integer',
            'auto_depreciate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
