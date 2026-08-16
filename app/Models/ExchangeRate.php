<?php

namespace App\Models;

use App\Services\Currency\ExchangeRateService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored exchange rate: "rate" quote units per 1 base unit on rate_date.
 *
 * Deliberately does NOT use {@see BelongsToCompany}: global provider rates have a
 * null company_id and the per-tenant scope would hide them. Lookups are done
 * explicitly through {@see ExchangeRateService}, which
 * scopes company overrides and global rows itself.
 */
#[Fillable([
    'company_id',
    'base_code',
    'quote_code',
    'rate',
    'rate_date',
    'source',
    'provider',
    'fetched_at',
])]
class ExchangeRate extends Model
{
    public const SOURCE_API = 'api';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_OPENING = 'opening';

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'rate_date' => 'date:Y-m-d',
            'fetched_at' => 'datetime',
        ];
    }
}
