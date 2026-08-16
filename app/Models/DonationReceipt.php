<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DonationReceiptStatus;
use App\Enums\GiftType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An official CRA donation receipt. The donor name/address are snapshotted at
 * save time. Cash gifts are record-only (the money is booked by a receipt/deposit
 * referenced via customer_receipt_id); in-kind gifts post their own GL entry at
 * fair market value via journal_entry_id.
 */
#[Fillable([
    'company_id', 'contact_id', 'receipt_no', 'status', 'gift_type',
    'gift_date', 'issued_date',
    'donor_name', 'donor_line1', 'donor_line2', 'donor_city', 'donor_region', 'donor_postal_code', 'donor_country',
    'amount_cents', 'advantage_cents', 'eligible_amount_cents',
    'advantage_description', 'in_kind_description', 'appraised_by', 'appraisal_date', 'currency_code',
    'revenue_account_id', 'debit_account_id', 'journal_entry_id', 'customer_receipt_id', 'donation_id', 'reissued_from_id',
    'is_consolidated', 'consolidation_year', 'email_sent_at',
    'void_reason', 'voided_at', 'voided_by_user_id', 'issued_by_user_id', 'notes',
])]
class DonationReceipt extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    /**
     * @return BelongsTo<DonationReceipt, $this>
     */
    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_id');
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DonationReceiptStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === DonationReceiptStatus::Issued;
    }

    public function isInKind(): bool
    {
        return $this->gift_type === GiftType::InKind;
    }

    public function hasAdvantage(): bool
    {
        return $this->advantage_cents > 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DonationReceiptStatus::class,
            'gift_type' => GiftType::class,
            'gift_date' => 'date:Y-m-d',
            'issued_date' => 'date:Y-m-d',
            'appraisal_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'advantage_cents' => 'integer',
            'eligible_amount_cents' => 'integer',
            'is_consolidated' => 'boolean',
            'consolidation_year' => 'integer',
            'email_sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
