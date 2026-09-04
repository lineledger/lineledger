<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Enums\BillStatus;
use App\Enums\BillType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'purchase_order_id', 'bill_type', 'bill_no', 'vendor_reference',
    'bill_date', 'due_date', 'terms_id', 'status',
    'subtotal_cents', 'tax_cents', 'total_cents', 'amount_paid_cents', 'reconciled_cents',
    'currency_code', 'fx_rate', 'home_total_cents',
    'memo', 'is_opening_balance',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id', 'recurring_document_id',
])]
class Bill extends Model
{
    use BelongsToCompany, GuardsPostedDeletion, SoftDeletes;

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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<BillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<BillPaymentApplication, $this>
     */
    public function paymentApplications(): HasMany
    {
        return $this->hasMany(BillPaymentApplication::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<RecurringDocument, $this>
     */
    public function recurringDocument(): BelongsTo
    {
        return $this->belongsTo(RecurringDocument::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * Total settled: cash bill payments plus any balance the GL already closed
     * outside the payment system (see {@see $reconciled_cents}).
     */
    public function settledCents(): int
    {
        return (int) $this->amount_paid_cents + (int) $this->reconciled_cents;
    }

    public function balanceCents(): int
    {
        return (int) $this->total_cents - $this->settledCents();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether this bill is denominated in a foreign (non-home) currency.
     * Requires the company relation, which the poster loads before calling.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null
            && ! $this->company->isHomeCurrency($this->currency_code);
    }

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

    public function scopeVendor(Builder $query): Builder
    {
        return $query->where('bill_type', BillType::Vendor->value);
    }

    /**
     * Open bills (posted or partial) that still carry a balance. The arithmetic
     * mirrors {@see balanceCents()} as portable SQL so MySQL and SQLite agree.
     *
     * @param  Builder<Bill>  $query
     */
    public function scopeOpenWithBalance(Builder $query): void
    {
        $query->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0');
    }

    public function scopeReimbursement(Builder $query): Builder
    {
        return $query->where('bill_type', BillType::Reimbursement->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'bill_type' => BillType::class,
            'status' => BillStatus::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'home_total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'reconciled_cents' => 'integer',
            'is_opening_balance' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
