<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_id', 'item_id', 'purchase_order_line_id', 'account_id', 'description',
    'quantity', 'unit_price_cents', 'line_discount_cents', 'line_discount_pct', 'tax_code_id', 'secondary_tax_code_id', 'secondary_tax_cents', 'secondary_tax_override_cents',
    'line_subtotal_cents', 'line_tax_cents', 'tax_override_cents', 'line_total_cents', 'line_order',
    'class_id', 'location_id', 'fund_id',
])]
class BillLine extends Model
{
    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function secondaryTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'secondary_tax_code_id')->withoutGlobalScopes();
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
            'quantity' => 'decimal:4',
            'unit_price_cents' => 'integer',
            'line_discount_cents' => 'integer',
            'line_discount_pct' => 'decimal:4',
            'line_subtotal_cents' => 'integer',
            'line_tax_cents' => 'integer',
            'tax_override_cents' => 'integer',
            'secondary_tax_cents' => 'integer',
            'secondary_tax_override_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }
}
