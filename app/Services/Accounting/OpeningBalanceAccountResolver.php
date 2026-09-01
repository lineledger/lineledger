<?php

namespace App\Services\Accounting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use RuntimeException;

/**
 * The one lookup for the Opening Balance Equity account. It is identified by
 * subtype + exact name — there is no dedicated flag — and non-profit charts
 * rename it, so every resolver must accept both {@see Account::OPENING_BALANCE_NAMES}.
 */
class OpeningBalanceAccountResolver
{
    public function resolve(int $companyId): ?Account
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subtype', AccountSubtype::Equity->value)
            ->whereIn('name', Account::OPENING_BALANCE_NAMES)
            ->first();
    }

    public function resolveOrFail(int $companyId): Account
    {
        $account = $this->resolve($companyId);

        if (! $account) {
            throw new RuntimeException("Missing 'Opening Balance Equity' account for company {$companyId}.");
        }

        return $account;
    }
}
