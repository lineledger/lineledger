<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'tax_return_id', 'payment_no', 'payment_date',
    'direction', 'status',
    'bank_account_id', 'payment_method_id', 'reference',
    'net_amount_cents',
    'penalty_cents', 'penalty_account_id',
    'interest_cents', 'interest_account_id',
    'commission_cents', 'commission_account_id',
    'total_cents', 'notes',
    'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id',
    'journal_entry_id',
])]
class TaxReturnPayment extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<TaxReturn, $this>
     */
    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function penaltyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'penalty_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function commissionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'commission_account_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function recalculateTotal(): void
    {
        if ($this->direction === TaxReturnPaymentDirection::Outgoing) {
            $this->total_cents = (int) $this->net_amount_cents
                + (int) $this->penalty_cents
                + (int) $this->interest_cents
                + (int) $this->commission_cents;
        } else {
            $this->total_cents = (int) $this->net_amount_cents + (int) $this->interest_cents;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'direction' => TaxReturnPaymentDirection::class,
            'status' => TaxReturnPaymentStatus::class,
            'net_amount_cents' => 'integer',
            'penalty_cents' => 'integer',
            'interest_cents' => 'integer',
            'commission_cents' => 'integer',
            'total_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
