<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Bundle item: a component item and the quantity of it the bundle
 * contains. Scoped through the parent (bundle) item.
 */
#[Fillable([
    'item_id', 'component_item_id', 'quantity', 'line_order',
])]
class ItemComponent extends Model
{
    /**
     * The bundle this component belongs to.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The item that is included in the bundle.
     *
     * @return BelongsTo<Item, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }
}
