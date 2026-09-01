<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\CreditMemoStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'sales_rep_id', 'credit_memo_no', 'credit_memo_date',
    'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'currency_code', 'fx_rate', 'home_total_cents',
    'memo', 'customer_message', 'is_opening_balance', 'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id', 'journal_entry_id',
])]
class CreditMemo extends Model
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
     * The employee credited with the sale, if any.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sales_rep_id');
    }

    /**
     * @return HasMany<CreditMemoLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditMemoLine::class)->orderBy('line_order');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * Cheques written to refund this credit memo (DR Accounts Receivable, CR Bank).
     *
     * @return HasMany<Cheque, $this>
     */
    public function refundCheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    /**
     * Negative customer receipts recording a credit-card refund of this credit
     * memo (DR Accounts Receivable, CR Undeposited Funds).
     *
     * @return HasMany<CustomerReceipt, $this>
     */
    public function refundReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    /**
     * Total already refunded to the client across all non-void refund cheques
     * (including drafts, so a pending draft can't be double-issued) and posted
     * credit-card refunds. Always returned as a positive amount of cents.
     */
    public function refundedCents(): int
    {
        $byCheque = (int) $this->refundCheques()
            ->where('status', '!=', 'void')
            ->sum('amount_cents');

        $byCard = (int) $this->refundReceipts()
            ->where('status', '!=', 'void')
            ->sum('amount_cents');

        return $byCheque - $byCard;
    }

    /**
     * Credit-memo value not yet refunded — the default and ceiling for a new refund.
     */
    public function remainingRefundableCents(): int
    {
        return max(0, (int) $this->total_cents - $this->refundedCents());
    }

    public function isFullyRefunded(): bool
    {
        return (int) $this->total_cents > 0 && $this->remainingRefundableCents() === 0;
    }

    /**
     * Whether this credit memo is denominated in a foreign (non-home) currency.
     * Requires the company relation, which the poster loads before calling.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null
            && ! $this->company->isHomeCurrency($this->currency_code);
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
            'credit_memo_date' => 'date:Y-m-d',
            'status' => CreditMemoStatus::class,
            'is_opening_balance' => 'boolean',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'home_total_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
