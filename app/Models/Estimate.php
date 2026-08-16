<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\EstimateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'sales_rep_id', 'estimate_no', 'estimate_date', 'expires_on',
    'terms_id', 'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'memo', 'customer_message', 'customer_po', 'converted_invoice_id', 'converted_sales_order_id',
])]
class Estimate extends Model
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
     * @return HasMany<EstimateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EstimateLine::class)->orderBy('line_order');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    /**
     * Whether this estimate is past its expiry date. Only Pending quotes expire;
     * comparison uses the company timezone, never UTC.
     */
    public function isExpired(): bool
    {
        return $this->status->isExpirable()
            && $this->expires_on !== null
            && $this->expires_on->lt($this->company->currentDateTime()->startOfDay());
    }

    /**
     * The status to display: overlays Expired onto an over-date Pending quote
     * without persisting it. Use this everywhere in views.
     */
    public function effectiveStatus(): EstimateStatus
    {
        return $this->isExpired() ? EstimateStatus::Expired : $this->status;
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
            'estimate_date' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'status' => EstimateStatus::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
