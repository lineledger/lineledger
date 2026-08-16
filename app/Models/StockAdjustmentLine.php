<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_adjustment_id', 'item_id',
    'qty_change', 'unit_cost_cents',
    'line_order', 'notes',
    'class_id', 'location_id', 'fund_id',
])]
class StockAdjustmentLine extends Model
{
    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_change' => 'decimal:4',
            'unit_cost_cents' => 'integer',
            'line_order' => 'integer',
        ];
    }
}
