<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Concerns\TracksCustomerRefunds;
use App\Enums\ChequeStatus;
use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property ReceiptStatus|null $status
 * @property Carbon|null $receipt_date
 */
#[Fillable([
    'company_id', 'contact_id', 'credit_memo_id', 'refunded_receipt_id', 'from_invoice_id',
    'receipt_no', 'receipt_date',
    'deposit_to_account_id', 'payment_method_id', 'reference',
    'amount_cents', 'currency_code', 'fx_rate', 'home_amount_cents', 'memo', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id', 'stripe_payment_intent_id', 'stripe_fee_cents',
])]
class CustomerReceipt extends Model
{
    use BelongsToCompany, GuardsPostedDeletion, SoftDeletes, TracksCustomerRefunds;

    /**
     * Where this receipt was applied BEFORE SaveReceipt last rewrote its
     * applications: the invoice ids the previous rows pointed at, and the
     * contact it belonged to. SaveReceipt records them on the instance it
     * returns; ReceiptPoster::repost() reads them so the invoices the receipt
     * no longer pays are recomputed too. Transient — never persisted.
     *
     * @var list<int>
     */
    protected array $previousApplicationInvoiceIds = [];

    protected ?int $previousContactId = null;

    /**
     * @param  list<int>  $invoiceIds
     */
    public function rememberPreviousApplications(array $invoiceIds, ?int $contactId): static
    {
        $this->previousApplicationInvoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        $this->previousContactId = $contactId;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function previousApplicationInvoiceIds(): array
    {
        return $this->previousApplicationInvoiceIds;
    }

    public function previousContactId(): ?int
    {
        return $this->previousContactId;
    }

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

    /**
     * The payment whose unapplied credit (an overpayment) this receipt refunds,
     * when it was created via "Refund to client → by credit card" on an
     * invoice or a payment. Like a credit memo refund it carries a negative
     * amount and applies to no invoices.
     *
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function refundedReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'refunded_receipt_id');
    }

    /**
     * The invoice a refund was issued from, so that invoice can list it.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function fromInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'from_invoice_id');
    }

    /**
     * Card refunds (negative receipts) paying back this payment's unapplied credit.
     *
     * @return HasMany<CustomerReceipt, $this>
     */
    public function refundReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class, 'refunded_receipt_id');
    }

    /**
     * Cheques paying back this payment's unapplied credit (DR Accounts Receivable, CR Bank).
     *
     * @return HasMany<Cheque, $this>
     */
    public function refundCheques(): HasMany
    {
        return $this->hasMany(Cheque::class, 'refunded_receipt_id');
    }

    public function refundLinkColumn(): string
    {
        return 'refunded_receipt_id';
    }

    /**
     * A payment can pay back only what it didn't apply to invoices. A refund
     * receipt has no credit of its own to refund.
     */
    public function refundableBaseCents(): int
    {
        if ($this->isRefund()) {
            return 0;
        }

        return max(0, (int) $this->amount_cents - $this->appliedCents());
    }

    /**
     * Whether this receipt pays money back to the customer — refunding a credit
     * memo or a payment's unapplied credit — rather than receiving it.
     */
    public function isRefund(): bool
    {
        return $this->credit_memo_id !== null || $this->refunded_receipt_id !== null;
    }

    /**
     * The credit memo or payment this refund pays back; null for an ordinary receipt.
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
     * Total applied to invoices, from the loaded applications when present.
     */
    public function appliedCents(): int
    {
        if ($this->relationLoaded('applications')) {
            return (int) $this->applications->sum('amount_cents');
        }

        return (int) $this->applications()->sum('amount_cents');
    }

    /**
     * The customer credit this payment still holds: what it neither applied to
     * an invoice nor paid back as a refund. Zero for a refund receipt.
     */
    public function unappliedCents(): int
    {
        if ($this->isRefund()) {
            return 0;
        }

        return (int) $this->amount_cents - $this->appliedCents() - $this->refundedCents();
    }

    /**
     * Whether this payment's unapplied credit can be refunded: a posted,
     * positive, home-currency payment that isn't itself a refund. Foreign
     * payments are excluded because their credit sits in a per-currency AR
     * control at the payment's own rate.
     */
    public function isRefundableSource(): bool
    {
        return $this->status === ReceiptStatus::Posted
            && ! $this->isRefund()
            && (int) $this->amount_cents > 0
            && ! $this->isForeignCurrency();
    }

    /**
     * Amount applied to invoices per receipt, in one grouped query.
     *
     * @param  array<int, int>  $receiptIds
     * @return array<int, int> receipt id => applied cents
     */
    public static function appliedCentsByReceipt(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        return ReceiptApplication::query()
            ->whereIn('customer_receipt_id', $receiptIds)
            ->groupBy('customer_receipt_id')
            ->selectRaw('customer_receipt_id, SUM(amount_cents) AS applied_cents')
            ->pluck('applied_cents', 'customer_receipt_id')
            ->map(fn ($cents) => (int) $cents)
            ->all();
    }

    /**
     * Amount refunded per receipt (non-void refund cheques, drafts included,
     * plus card refunds), as positive cents, in two grouped queries.
     *
     * @param  array<int, int>  $receiptIds
     * @return array<int, int> receipt id => refunded cents
     */
    public static function refundedCentsByReceipt(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        $byCheque = Cheque::query()
            ->whereIn('refunded_receipt_id', $receiptIds)
            ->where('status', '!=', ChequeStatus::Void->value)
            ->groupBy('refunded_receipt_id')
            ->select('refunded_receipt_id', DB::raw('SUM(amount_cents) AS refunded_cents'))
            ->pluck('refunded_cents', 'refunded_receipt_id');

        $byCard = CustomerReceipt::query()
            ->whereIn('refunded_receipt_id', $receiptIds)
            ->where('status', '!=', ReceiptStatus::Void->value)
            ->groupBy('refunded_receipt_id')
            ->select('refunded_receipt_id', DB::raw('SUM(amount_cents) AS refunded_cents'))
            ->pluck('refunded_cents', 'refunded_receipt_id');

        $refunded = [];
        foreach ($receiptIds as $id) {
            $cents = (int) ($byCheque[$id] ?? 0) - (int) ($byCard[$id] ?? 0);
            if ($cents !== 0) {
                $refunded[(int) $id] = $cents;
            }
        }

        return $refunded;
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
