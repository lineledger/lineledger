<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bill_payment_id', 'bill_id', 'amount_cents'])]
class BillPaymentApplication extends Model
{
    /**
     * @return BelongsTo<BillPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillPayment::class, 'bill_payment_id');
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class)->withoutGlobalScopes();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }
}
