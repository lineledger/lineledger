<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'sales_rep_id', 'order_no', 'order_date', 'expected_date',
    'terms_id', 'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'memo', 'customer_message', 'customer_po', 'ship_date', 'ship_via', 'fob', 'tracking_no',
])]
class SalesOrder extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'terms_id');
    }

    /**
     * The employee credited with the sale, if any.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_rep_id');
    }

    /**
     * @return HasMany<SalesOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_order');
    }

    /**
     * Invoices generated from this order by fulfillment.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The status to display, deriving fulfillment from linked invoices so a
     * voided/deleted fulfillment invoice reverts the order without callbacks:
     * Cancelled is sticky; otherwise Closed when every line is fully invoiced,
     * Partial when some quantity is invoiced, else Open. Use this everywhere.
     */
    public function effectiveStatus(): SalesOrderStatus
    {
        if ($this->status === SalesOrderStatus::Cancelled) {
            return SalesOrderStatus::Cancelled;
        }

        $this->loadMissing('lines');

        if ($this->lines->isEmpty()) {
            return SalesOrderStatus::Open;
        }

        $anyInvoiced = false;
        $allFulfilled = true;

        foreach ($this->lines as $line) {
            if ($line->qtyInvoiced() > 0.00001) {
                $anyInvoiced = true;
            }
            if ($line->qtyBackordered() > 0.00001) {
                $allFulfilled = false;
            }
        }

        if ($allFulfilled) {
            return SalesOrderStatus::Closed;
        }

        return $anyInvoiced ? SalesOrderStatus::Partial : SalesOrderStatus::Open;
    }

    /**
     * Recalculate totals from line items and persist.
     */
    public function recalculateTotals(): void
    {
        $this->loadMissing('lines');

        $subtotal = (int) $this->lines->sum('line_subtotal_cents');
        $tax = (int) $this->lines->sum('line_tax_cents') + (int) $this->lines->sum('secondary_tax_cents');

        $this->forceFill([
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $tax,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'ship_date' => 'date:Y-m-d',
            'status' => SalesOrderStatus::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
