<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\RecurrenceFrequency;
use Database\Factories\MembershipLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A membership tier (e.g. Individual, Family, Corporate). Carries the default dues
 * amount and billing cadence used when generating dues invoices, plus the revenue
 * account those invoices credit. Per-member overrides live on {@see Member}.
 */
#[Fillable([
    'company_id', 'name', 'default_dues_cents', 'billing_frequency',
    'revenue_account_id', 'default_terms_id', 'default_tax_code_id', 'is_active',
])]
class MembershipLevel extends Model
{
    /** @use HasFactory<MembershipLevelFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_dues_cents' => 'integer',
            'billing_frequency' => RecurrenceFrequency::class,
            'is_active' => 'boolean',
        ];
    }
}
