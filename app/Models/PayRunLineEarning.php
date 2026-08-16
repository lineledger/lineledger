<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'pay_run_line_id', 'code', 'name', 'amount_cents', 'hours', 'is_override',
    'is_pensionable', 'is_insurable', 'is_taxable', 'is_bonus_method', 'add_to_net_pay_only', 'add_to_bases_only',
    'expense_account_id', 't4_box', 'class_id', 'location_id', 'line_order',
])]
class PayRunLineEarning extends Model
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
            'hours' => 'decimal:2',
            'is_override' => 'boolean',
            'is_pensionable' => 'boolean',
            'is_insurable' => 'boolean',
            'is_taxable' => 'boolean',
            'is_bonus_method' => 'boolean',
            'add_to_net_pay_only' => 'boolean',
            'add_to_bases_only' => 'boolean',
            'line_order' => 'integer',
        ];
    }
}
