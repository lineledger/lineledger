<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'pay_run_line_id', 'code', 'name', 'amount_cents',
    'expense_account_id', 'liability_account_id', 't4_box', 'line_order',
])]
class PayRunLineContribution extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<PayRunLine, $this>
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'line_order' => 'integer',
        ];
    }
}
