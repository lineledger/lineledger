<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxReturnStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'tax_agency_id', 'tax_return_no',
    'period_start', 'period_end', 'status',
    'collected_cents', 'paid_cents', 'net_cents',
    'filing_reference', 'notes', 'excluded_journal_line_ids',
    'filed_at', 'filed_by_user_id',
    'voided_at', 'voided_by_user_id', 'void_reason',
])]
class TaxReturn extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<TaxAgency, $this>
     */
    public function taxAgency(): BelongsTo
    {
        return $this->belongsTo(TaxAgency::class);
    }

    /**
     * @return HasMany<TaxReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxReturnLine::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<TaxReturnPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TaxReturnPayment::class)->orderByDesc('payment_date');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'status' => TaxReturnStatus::class,
            'collected_cents' => 'integer',
            'paid_cents' => 'integer',
            'net_cents' => 'integer',
            'excluded_journal_line_ids' => 'array',
            'filed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
