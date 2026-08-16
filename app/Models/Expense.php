<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pay-now expense (QuickBooks "Expense"): money out via card, Interac, EFT,
 * debit, online, or cash — paid from a bank or credit-card account, tagged with
 * a payment method. Mirrors {@see Cheque}, which stays the dedicated print-a-
 * cheque document.
 *
 * @property ExpenseStatus $status
 */
#[Fillable([
    'company_id', 'payment_account_id', 'payment_method_id', 'reference', 'expense_date',
    'payee_contact_id', 'payee_name', 'memo', 'amount_cents',
    'currency_code', 'fx_rate', 'home_amount_cents', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class Expense extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * The bank (asset) or credit-card (liability) account the money came from.
     *
     * @return BelongsTo<Account, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'payee_contact_id');
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('line_order');
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
            'expense_date' => 'date:Y-m-d',
            'status' => ExpenseStatus::class,
            'amount_cents' => 'integer',
            'home_amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
