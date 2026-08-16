<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'item_id', 'stock_movement_id',
    'qty_remaining', 'unit_cost_cents',
])]
class StockLayer extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_remaining' => 'decimal:4',
            'unit_cost_cents' => 'integer',
        ];
    }
}
