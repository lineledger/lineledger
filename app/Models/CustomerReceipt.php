<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'credit_memo_id', 'receipt_no', 'receipt_date',
    'deposit_to_account_id', 'payment_method_id', 'reference',
    'amount_cents', 'currency_code', 'fx_rate', 'home_amount_cents', 'memo', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id', 'stripe_payment_intent_id', 'stripe_fee_cents',
])]
class CustomerReceipt extends Model
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
     * @return BelongsTo<Account, $this>
     */
    public function depositToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_to_account_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<ReceiptApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(ReceiptApplication::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The credit memo this receipt refunds, when it was created via
     * "Refund to client → by credit card". Such receipts carry a negative
     * amount (cash leaving via the debit machine) and apply to no invoices.
     *
     * @return BelongsTo<CreditMemo, $this>
     */
    public function creditMemo(): BelongsTo
    {
        return $this->belongsTo(CreditMemo::class);
    }

    public function isRefund(): bool
    {
        return $this->credit_memo_id !== null;
    }

    /**
     * Whether this receipt is denominated in a foreign (non-home) currency.
     * Requires the company relation, which the poster loads before calling.
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_code !== null
            && ! $this->company->isHomeCurrency($this->currency_code);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_date' => 'date:Y-m-d',
            'status' => ReceiptStatus::class,
            'amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
