<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id', 'item_id', 'movement_date',
    'qty_change', 'unit_cost_cents', 'total_cost_cents',
    'source_type', 'source_id', 'source_line_id',
    'journal_entry_id', 'reversal_of_movement_id',
    'consumed_layers', 'notes',
])]
class StockMovement extends Model
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
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_date' => 'date:Y-m-d',
            'qty_change' => 'decimal:4',
            'unit_cost_cents' => 'integer',
            'total_cost_cents' => 'integer',
            'consumed_layers' => 'array',
        ];
    }
}
