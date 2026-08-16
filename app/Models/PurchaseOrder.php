<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'po_no', 'po_date', 'expected_date',
    'terms_id', 'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'memo', 'vendor_message', 'ship_to',
])]
class PurchaseOrder extends Model
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
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_order');
    }

    /**
     * Bills generated from this order by receiving/billing.
     *
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * The status to display, deriving receipt from linked bills so a voided/deleted
     * bill reverts the order without callbacks: Cancelled is sticky; otherwise Closed
     * when every line is fully billed, Partial when some quantity is billed, else Open.
     * Use this everywhere.
     */
    public function effectiveStatus(): PurchaseOrderStatus
    {
        if ($this->status === PurchaseOrderStatus::Cancelled) {
            return PurchaseOrderStatus::Cancelled;
        }

        $this->loadMissing('lines');

        if ($this->lines->isEmpty()) {
            return PurchaseOrderStatus::Open;
        }

        $anyBilled = false;
        $allReceived = true;

        foreach ($this->lines as $line) {
            if ($line->qtyBilled() > 0.00001) {
                $anyBilled = true;
            }
            if ($line->qtyBackordered() > 0.00001) {
                $allReceived = false;
            }
        }

        if ($allReceived) {
            return PurchaseOrderStatus::Closed;
        }

        return $anyBilled ? PurchaseOrderStatus::Partial : PurchaseOrderStatus::Open;
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
            'po_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'status' => PurchaseOrderStatus::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
