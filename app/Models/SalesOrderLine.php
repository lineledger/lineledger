<?php

namespace App\Models;

use App\Actions\Sales\FulfillSalesOrder;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sales_order_id', 'item_id', 'account_id', 'description', 'service_date',
    'quantity', 'unit_price_cents', 'line_discount_cents', 'line_discount_pct', 'tax_code_id', 'secondary_tax_code_id', 'secondary_tax_cents',
    'line_subtotal_cents', 'line_tax_cents', 'line_total_cents', 'line_order',
    'class_id', 'location_id', 'fund_id',
])]
class SalesOrderLine extends Model
{
    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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
     * Invoice lines that draw down this order line. The link is set by
     * {@see FulfillSalesOrder} on each fulfillment invoice.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function qtyOrdered(): float
    {
        return (float) $this->quantity;
    }

    /**
     * Quantity already invoiced against this line, summed live from linked,
     * non-void invoice lines. Voiding or deleting a fulfillment invoice drops it
     * from this sum automatically — no counter to maintain.
     */
    public function qtyInvoiced(): float
    {
        if ($this->relationLoaded('invoiceLines')) {
            return (float) $this->invoiceLines
                ->filter(fn (InvoiceLine $line): bool => $line->invoice !== null
                    && $line->invoice->status !== InvoiceStatus::Void)
                ->sum(fn (InvoiceLine $line): float => (float) $line->quantity);
        }

        return (float) $this->invoiceLines()
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Void->value))
            ->sum('quantity');
    }

    /**
     * Quantity still outstanding (ordered minus invoiced), floored at zero.
     */
    public function qtyBackordered(): float
    {
        return max(0.0, $this->qtyOrdered() - $this->qtyInvoiced());
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
            'service_date' => 'date:Y-m-d',
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
