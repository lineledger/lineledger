<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'pay_run_line_id', 'code', 'name', 'amount_cents', 'is_override',
    'reduces_taxable', 'liability_account_id', 't4_box', 'line_order',
])]
class PayRunLineDeduction extends Model
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
            'is_override' => 'boolean',
            'reduces_taxable' => 'boolean',
            'line_order' => 'integer',
        ];
    }
}
