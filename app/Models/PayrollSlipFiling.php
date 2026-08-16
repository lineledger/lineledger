<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\SlipType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finalized year-end slip filing (T4 / RL-1 / T4A) for one company + year.
 * Its existence is the lock: the report pages and the employee portal read the
 * snapshotted {@see PayrollSlipFilingLine} rows and the summary captured here
 * instead of the live calculator. Unlocking deletes the filing (lines cascade),
 * returning the year to live-computed "draft".
 *
 * @property SlipType $slip_type
 * @property int $year
 * @property CarbonImmutable $finalized_at
 * @property array<string, mixed> $summary
 */
#[Fillable([
    'company_id', 'slip_type', 'year', 'finalized_at', 'finalized_by_user_id', 'summary',
])]
class PayrollSlipFiling extends Model
{
    use BelongsToCompany;

    /**
     * @return HasMany<PayrollSlipFilingLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollSlipFilingLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slip_type' => SlipType::class,
            'year' => 'integer',
            'finalized_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }
}
