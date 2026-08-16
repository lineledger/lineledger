<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\MembershipStatus;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A membership record — one per contact per company. Holds the tier, term dates,
 * and per-member dues override. The lifecycle status is derived from the dates and
 * cancellation (see {@see self::effectiveStatus()}), never stored. Dues are billed
 * as invoices linked back via invoices.member_id.
 */
#[Fillable([
    'company_id', 'contact_id', 'membership_level_id', 'member_no',
    'joined_on', 'started_on', 'expires_on', 'dues_cents', 'auto_renew',
    'recurring_document_id', 'cancelled_at', 'notes', 'is_active',
])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * Grace window (days) after expiry during which a membership is Lapsed rather
     * than fully Expired — the renewal nudge period.
     */
    public const GRACE_DAYS = 30;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<MembershipLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'membership_level_id');
    }

    /**
     * @return BelongsTo<RecurringDocument, $this>
     */
    public function recurringDocument(): BelongsTo
    {
        return $this->belongsTo(RecurringDocument::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The effective dues amount — the per-member override, falling back to the
     * level's default.
     */
    public function effectiveDuesCents(): int
    {
        return $this->dues_cents ?? $this->level?->default_dues_cents ?? 0;
    }

    /**
     * Derive the membership status from cancellation + term dates, in the company
     * timezone. Mirrors {@see Estimate::effectiveStatus()} — never persisted.
     */
    public function effectiveStatus(): MembershipStatus
    {
        if ($this->cancelled_at !== null) {
            return MembershipStatus::Cancelled;
        }

        if ($this->expires_on === null) {
            return MembershipStatus::Active;
        }

        $today = $this->company->currentDateTime()->startOfDay();

        if ($this->expires_on->gte($today)) {
            return MembershipStatus::Active;
        }

        return $this->expires_on->gte($today->subDays(self::GRACE_DAYS))
            ? MembershipStatus::Lapsed
            : MembershipStatus::Expired;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_on' => 'date:Y-m-d',
            'started_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'dues_cents' => 'integer',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
