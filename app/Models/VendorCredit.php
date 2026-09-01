<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\VendorCreditStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'contact_id', 'vendor_credit_no', 'vendor_credit_date',
    'status', 'subtotal_cents', 'tax_cents', 'total_cents',
    'currency_code', 'fx_rate', 'home_total_cents',
    'memo', 'vendor_message', 'is_opening_balance', 'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id', 'journal_entry_id',
])]
class VendorCredit extends Model
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
     * @return HasMany<VendorCreditLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorCreditLine::class)->orderBy('line_order');
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
     * Whether this vendor credit is denominated in a foreign (non-home) currency.
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
            'vendor_credit_date' => 'date:Y-m-d',
            'status' => VendorCreditStatus::class,
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
