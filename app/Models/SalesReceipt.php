<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Concerns\GuardsPostedDeletion;
use App\Enums\SalesReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pay-now sale: one document that books revenue + sales tax and takes the cash
 * in a single posted entry, with NO Accounts Receivable. The cash debit lands in
 * the chosen deposit-to account (Undeposited Funds or a bank), so a UF-parked
 * sales receipt can later be batched into a Bank Deposit just like a receipt.
 *
 *   DR  Deposit-to (Undeposited Funds / Bank)   total
 *   CR    Income (per account, grouped)         subtotal
 *   CR    Tax Payable (per agency, grouped)     tax
 *   DR  COGS / CR Inventory                     (tracked items)
 *
 * @property SalesReceiptStatus $status
 */
#[Fillable([
    'company_id', 'contact_id', 'sales_receipt_no', 'receipt_date',
    'deposit_to_account_id', 'payment_method_id', 'reference',
    'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'currency_code', 'fx_rate', 'home_total_cents',
    'memo',
    'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id', 'journal_entry_id',
])]
class SalesReceipt extends Model
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
        return $this->belongsTo(Account::class, 'deposit_to_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<SalesReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesReceiptLine::class)->orderBy('line_order');
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
     * Whether this receipt is denominated in a foreign (non-home) currency.
     * Its *_cents columns then hold foreign amounts. Requires the company
     * relation, which the poster loads before calling this.
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
            'receipt_date' => 'date:Y-m-d',
            'status' => SalesReceiptStatus::class,
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'home_total_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
