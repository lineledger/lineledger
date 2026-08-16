<?php

namespace App\Actions\MasterData;

use App\Enums\FundType;
use App\Models\Company;
use App\Models\Fund;

/**
 * Guarantees a company has exactly one default "General Fund" — the unrestricted
 * catch-all that untagged GL activity is attributed to in per-fund reporting.
 * Idempotent; called when fund tracking is enabled.
 */
final class EnsureDefaultFund
{
    public function handle(Company $company): Fund
    {
        return Fund::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                ['company_id' => $company->id, 'is_default' => true],
                ['name' => 'General Fund', 'fund_type' => FundType::General->value, 'is_active' => true],
            );
    }
}
