<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The anchor for the post-setup Opening Balances workspace (one per company).
 * Holds the as-of date and the maintained opening journal entry the
 * synchronizer keeps in step with the draft trial balance targets.
 *
 * @see OpeningBalanceJournalSynchronizer
 */
#[Fillable([
    'company_id', 'as_of_date', 'status', 'journal_entry_id',
    'applied_at', 'apply_error', 'created_by_user_id',
])]
class OpeningBalanceState extends Model
{
    use BelongsToCompany;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINALIZED = 'finalized';

    /**
     * @return HasMany<OpeningBalanceRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(OpeningBalanceRow::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->as_of_date);
    }

    public static function for(Company $company): ?self
    {
        return self::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();
    }

    /**
     * The most recent fiscal year-end on or before today — the standard
     * accountant conversion date (mirrors the QuickBooks wizard's default).
     */
    public static function defaultAsOfDate(Company $company): CarbonImmutable
    {
        $today = $company->currentDateTime()->startOfDay();
        $fyStart = $today->setDate($today->year, (int) ($company->fiscal_year_start_month ?? 1), 1);

        if ($fyStart->greaterThan($today)) {
            $fyStart = $fyStart->subYear();
        }

        return $fyStart->subDay();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
        ];
    }
}
