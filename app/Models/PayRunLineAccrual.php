<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'pay_run_line_id', 'code', 'name', 'calc_basis',
    'amount_cents', 'hours', 'expense_account_id', 'liability_account_id', 'line_order',
])]
class PayRunLineAccrual extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<PayRunLine, $this>
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class);
    }

    /** Whether this accrual is measured in dollars (vs hours). */
    public function isDollar(): bool
    {
        return in_array($this->calc_basis, ['dollars', 'percent_of_earnings', 'cents_per_hour'], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'hours' => 'decimal:2',
            'line_order' => 'integer',
        ];
    }
}
