<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DonationStatus;
use App\Enums\GiftType;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recorded donation — the money side of a gift. Posting books the cash in:
 *   DR deposit account / CR donation revenue (unrestricted), or
 *   DR deposit account / CR deferred-restricted liability (restricted, deferral method), or
 *   DR deposit account / CR donation revenue tagged with the fund (restricted fund method).
 * The official CRA receipt is a separate artifact, linked via donation_receipt_id.
 */
#[Fillable([
    'company_id', 'contact_id', 'donation_no', 'status', 'gift_type', 'donation_date',
    'amount_cents', 'currency_code', 'is_restricted', 'fund_id', 'restriction_note',
    'deposit_to_account_id', 'revenue_account_id', 'deferred_account_id',
    'journal_entry_id', 'donation_receipt_id', 'notes',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id',
])]
class Donation extends Model
{
    /** @use HasFactory<DonationFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Fund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<DonationReceipt, $this>
     */
    public function donationReceipt(): BelongsTo
    {
        return $this->belongsTo(DonationReceipt::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depositToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_to_account_id');
    }

    public function isDraft(): bool
    {
        return $this->status === DonationStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === DonationStatus::Posted;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DonationStatus::class,
            'gift_type' => GiftType::class,
            'donation_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'is_restricted' => 'boolean',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
