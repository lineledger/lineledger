<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\MemorizedReportGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's named grouping of memorized reports within a company (QuickBooks
 * "memorized report group"). Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'name',
    'sort_order',
])]
class MemorizedReportGroup extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<MemorizedReportGroupFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MemorizedReport, $this>
     */
    public function memorizedReports(): HasMany
    {
        return $this->hasMany(MemorizedReport::class)->orderBy('sort_order')->orderBy('id');
    }
}
