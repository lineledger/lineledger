<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'adjustment_no', 'adjustment_date', 'reason', 'notes',
    'journal_entry_id', 'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id',
])]
class StockAdjustment extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return HasMany<StockAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date:Y-m-d',
            'reason' => StockAdjustmentReason::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
