<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deposit_id', 'customer_receipt_id', 'sales_receipt_id', 'account_id', 'contact_id',
    'description', 'amount_cents', 'line_order',
    'class_id', 'location_id', 'fund_id',
])]
class DepositLine extends Model
{
    /**
     * @return BelongsTo<Deposit, $this>
     */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function customerReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<SalesReceipt, $this>
     */
    public function salesReceipt(): BelongsTo
    {
        return $this->belongsTo(SalesReceipt::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withoutGlobalScopes();
    }

    public function isReceiptSource(): bool
    {
        return $this->customer_receipt_id !== null || $this->sales_receipt_id !== null;
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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
