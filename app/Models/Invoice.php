<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $invoice_date
 * @property Carbon|null $due_date
 * @property string|null $currency_code
 * @property string|null $fx_rate
 */
#[Fillable([
    'company_id', 'contact_id', 'sales_rep_id', 'sales_order_id', 'invoice_no', 'invoice_date', 'due_date',
    'terms_id', 'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'document_discount_cents', 'document_discount_pct',
    'currency_code', 'fx_rate', 'home_total_cents',
    'amount_paid_cents', 'reconciled_cents', 'memo', 'is_opening_balance',
    'customer_po', 'ship_date', 'ship_via', 'fob', 'tracking_no', 'customer_message',
    'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id', 'journal_entry_id', 'recurring_document_id', 'member_id',
    'form_style_id', 'reminders_enabled',
])]
class Invoice extends Model
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
     * The employee credited with the sale, if any.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_rep_id');
    }

    /**
     * The sales order this invoice was generated from, if any.
     *
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * The form style (PDF template overrides) chosen for this invoice, if any.
     *
     * @return BelongsTo<FormStyle, $this>
     */
    public function formStyle(): BelongsTo
    {
        return $this->belongsTo(FormStyle::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_order');
    }

    /**
     * Milestone / deposit payment requests, in display order. A presentation
     * layer over the invoice's AR balance — they post nothing to the ledger.
     *
     * @return HasMany<InvoicePaymentRequest, $this>
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(InvoicePaymentRequest::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<ReceiptApplication, $this>
     */
    public function receiptApplications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    /**
     * @return BelongsTo<RecurringDocument, $this>
     */
    public function recurringDocument(): BelongsTo
    {
        return $this->belongsTo(RecurringDocument::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * Total settled: cash received against the invoice plus any balance reconciled
     * away by a journal entry that already cleared AR (see {@see $reconciled_cents}).
     */
    public function settledCents(): int
    {
        return (int) $this->amount_paid_cents + (int) $this->reconciled_cents;
    }

    public function balanceCents(): int
    {
        return (int) $this->total_cents - $this->settledCents();
    }

    /**
     * Open invoices (posted or partial) that still have an outstanding balance.
     * The balance arithmetic mirrors {@see balanceCents()} as portable SQL so
     * MySQL and SQLite agree — shared by the overdue-AR insight and the dunning
     * reminder resolver.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpenWithBalance(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0');
    }

    /**
     * Whether this invoice is denominated in a foreign (non-home) currency.
     * Its *_cents columns then hold foreign amounts. Requires the company
     * relation, which the posters load before calling this.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null
            && ! $this->company->isHomeCurrency($this->currency_code);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
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
            // A document-level discount reduces what the customer owes; it posts
            // separately to a contra-revenue account, so gross income is unchanged.
            'total_cents' => $subtotal + $tax - (int) $this->document_discount_cents,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'ship_date' => 'date:Y-m-d',
            'status' => InvoiceStatus::class,
            'reminders_enabled' => 'boolean',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'document_discount_cents' => 'integer',
            'document_discount_pct' => 'decimal:4',
            'home_total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'reconciled_cents' => 'integer',
            'is_opening_balance' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
