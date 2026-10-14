<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ChequeStatus|null $status
 */
#[Fillable([
    'company_id', 'bank_account_id', 'cheque_no', 'cheque_date',
    'payee_contact_id', 'credit_memo_id', 'refunded_receipt_id', 'from_invoice_id', 'payee_name', 'memo', 'is_opening_balance', 'amount_cents',
    'payee_line1', 'payee_line2', 'payee_city', 'payee_region', 'payee_postal_code', 'payee_country',
    'currency_code', 'fx_rate', 'home_amount_cents', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class Cheque extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'payee_contact_id');
    }

    /**
     * @return HasMany<ChequeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ChequeLine::class)->orderBy('line_order');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The credit memo this cheque refunds, when it was created via
     * "Refund to client → by cheque". Null for ordinary expense cheques.
     *
     * @return BelongsTo<CreditMemo, $this>
     */
    public function creditMemo(): BelongsTo
    {
        return $this->belongsTo(CreditMemo::class);
    }

    /**
     * The payment whose unapplied credit (an overpayment) this cheque refunds,
     * when it was created via "Refund to client → by cheque" on an invoice or
     * a payment.
     *
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function refundedReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'refunded_receipt_id');
    }

    /**
     * The invoice a refund cheque was issued from, so that invoice can list it.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function fromInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'from_invoice_id');
    }

    /**
     * Whether this cheque pays a customer back — refunding a credit memo or a
     * payment's unapplied credit — rather than an ordinary expense cheque.
     */
    public function isRefund(): bool
    {
        return $this->credit_memo_id !== null || $this->refunded_receipt_id !== null;
    }

    /**
     * The credit memo or payment this refund cheque pays back; null for an ordinary cheque.
     */
    public function refundSource(): CreditMemo|CustomerReceipt|null
    {
        if ($this->credit_memo_id !== null) {
            return $this->creditMemo;
        }

        if ($this->refunded_receipt_id !== null) {
            return $this->refundedReceipt;
        }

        return null;
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    public function recalculateAmount(): void
    {
        $this->loadMissing('lines');

        $total = (int) $this->lines->sum(fn ($l) => (int) $l->amount_cents + (int) $l->tax_cents + (int) $l->secondary_tax_cents);

        $this->forceFill(['amount_cents' => $total])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_date' => 'date:Y-m-d',
            'status' => ChequeStatus::class,
            'is_opening_balance' => 'boolean',
            'amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
