<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\ItemType;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'name', 'sku', 'description', 'type', 'item_category_id',
    'income_account_id', 'expense_account_id',
    'track_inventory', 'inventory_asset_account_id', 'cogs_account_id',
    'reorder_point', 'qty_on_hand_cached', 'unit_cost_cents_cached',
    'default_price_cents', 'default_tax_code_id', 'default_secondary_tax_code_id', 'is_active',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function inventoryAssetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_asset_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }

    /**
     * The optional second default sales tax (e.g. PST/QST alongside GST). Prefills
     * the line's secondary_tax_code_id when the item is selected on a document.
     *
     * @return BelongsTo<TaxCode, $this>
     */
    public function defaultSecondaryTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_secondary_tax_code_id');
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * The component lines, present only on a Bundle item.
     *
     * @return HasMany<ItemComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ItemComponent::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<StockLayer, $this>
     */
    public function stockLayers(): HasMany
    {
        return $this->hasMany(StockLayer::class);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->reorder_point !== null
            && (float) $this->qty_on_hand_cached < (float) $this->reorder_point;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'default_price_cents' => 'integer',
            'is_active' => 'boolean',
            'track_inventory' => 'boolean',
            'reorder_point' => 'decimal:4',
            'qty_on_hand_cached' => 'decimal:4',
            'unit_cost_cents_cached' => 'integer',
        ];
    }
}
