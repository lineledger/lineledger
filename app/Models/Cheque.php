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

#[Fillable([
    'company_id', 'bank_account_id', 'cheque_no', 'cheque_date',
    'payee_contact_id', 'credit_memo_id', 'payee_name', 'memo', 'is_opening_balance', 'amount_cents',
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

    public function isRefund(): bool
    {
        return $this->credit_memo_id !== null;
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
