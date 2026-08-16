<?php

namespace App\Models;

use App\Actions\Purchasing\FulfillPurchaseOrder;
use App\Enums\BillStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'item_id', 'account_id', 'description',
    'quantity', 'unit_price_cents', 'line_discount_cents', 'line_discount_pct', 'tax_code_id', 'secondary_tax_code_id', 'secondary_tax_cents',
    'line_subtotal_cents', 'line_tax_cents', 'line_total_cents', 'line_order',
    'class_id', 'location_id', 'fund_id',
])]
class PurchaseOrderLine extends Model
{
    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
     * Bill lines that draw down this order line. The link is set by
     * {@see FulfillPurchaseOrder} on each generated bill.
     *
     * @return HasMany<BillLine, $this>
     */
    public function billLines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function qtyOrdered(): float
    {
        return (float) $this->quantity;
    }

    /**
     * Quantity already billed against this line, summed live from linked, non-void
     * bill lines. Voiding or deleting a bill drops it from this sum automatically —
     * no counter to maintain.
     */
    public function qtyBilled(): float
    {
        if ($this->relationLoaded('billLines')) {
            return (float) $this->billLines
                ->filter(fn (BillLine $line): bool => $line->bill !== null
                    && $line->bill->status !== BillStatus::Void)
                ->sum(fn (BillLine $line): float => (float) $line->quantity);
        }

        return (float) $this->billLines()
            ->whereHas('bill', fn ($query) => $query->where('status', '!=', BillStatus::Void->value))
            ->sum('quantity');
    }

    /**
     * Quantity still outstanding (ordered minus billed), floored at zero.
     */
    public function qtyBackordered(): float
    {
        return max(0.0, $this->qtyOrdered() - $this->qtyBilled());
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
            'secondary_tax_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }
}
