<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated month of book depreciation for one asset — the idempotency
 * ledger behind the nightly depreciation generator. `period` is the first day
 * of the depreciated month; `journal_entry_id` points at the draft the month
 * was bundled into. Cascades away with the journal entry, so deleting a draft
 * re-opens its months; voiding a posted entry keeps the rows (month stays done).
 *
 * @property CarbonInterface $period
 */
#[Fillable([
    'company_id',
    'asset_id',
    'journal_entry_id',
    'period',
    'amount_cents',
])]
class AssetDepreciationEntry extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date:Y-m-d',
            'amount_cents' => 'integer',
        ];
    }
}
