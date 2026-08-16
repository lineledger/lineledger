<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\FundType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A fund — a transaction-tagging dimension for the ASNPO restricted fund method.
 * Mirrors {@see Classification}/{@see Location} plus a restriction type and the
 * per-company default "General Fund" catch-all.
 */
#[Fillable(['company_id', 'name', 'fund_type', 'is_default', 'is_active'])]
class Fund extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fund_type' => FundType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The company's default "General Fund" (the unrestricted catch-all), if seeded.
     */
    public static function defaultFor(Company $company): ?self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->first();
    }
}
