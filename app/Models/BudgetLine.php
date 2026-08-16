<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'budget_id', 'company_id', 'account_id',
    'month_1_cents', 'month_2_cents', 'month_3_cents', 'month_4_cents',
    'month_5_cents', 'month_6_cents', 'month_7_cents', 'month_8_cents',
    'month_9_cents', 'month_10_cents', 'month_11_cents', 'month_12_cents',
    'line_order',
])]
class BudgetLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * Total budgeted across all twelve fiscal months, in cents.
     */
    public function totalCents(): int
    {
        $total = 0;

        for ($month = 1; $month <= 12; $month++) {
            $total += (int) $this->{"month_{$month}_cents"};
        }

        return $total;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month_1_cents' => 'integer',
            'month_2_cents' => 'integer',
            'month_3_cents' => 'integer',
            'month_4_cents' => 'integer',
            'month_5_cents' => 'integer',
            'month_6_cents' => 'integer',
            'month_7_cents' => 'integer',
            'month_8_cents' => 'integer',
            'month_9_cents' => 'integer',
            'month_10_cents' => 'integer',
            'month_11_cents' => 'integer',
            'month_12_cents' => 'integer',
            'line_order' => 'integer',
        ];
    }
}
