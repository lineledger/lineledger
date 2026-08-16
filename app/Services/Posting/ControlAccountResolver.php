<?php

namespace App\Services\Posting;

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyCurrency;
use RuntimeException;

/**
 * Resolves the AR/AP control account for a document's currency. For the home
 * currency this is the single home-currency system account (currency_code null);
 * for a foreign currency it is the per-currency control account wired on the
 * {@see CompanyCurrency} row by {@see EnableCompanyCurrency}.
 *
 * Replaces the bare "first is_system account by subtype" lookups in the posters,
 * which would otherwise ambiguously match foreign control accounts too.
 */
class ControlAccountResolver
{
    public function resolve(Company $company, AccountSubtype $subtype, ?string $currencyCode = null): Account
    {
        if ($company->isHomeCurrency($currencyCode)) {
            return $this->homeControlAccount($company, $subtype);
        }

        return $this->foreignControlAccount($company, $subtype, mb_strtoupper((string) $currencyCode));
    }

    private function homeControlAccount(Company $company, AccountSubtype $subtype): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', $subtype->value)
            ->whereNull('currency_code')
            ->where('is_system', true)
            ->orderBy('code')
            ->first();

        if ($account === null) {
            throw new RuntimeException("Missing system account [{$subtype->value}] on company {$company->id}.");
        }

        return $account;
    }

    private function foreignControlAccount(Company $company, AccountSubtype $subtype, string $currencyCode): Account
    {
        $currency = CompanyCurrency::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('currency_code', $currencyCode)
            ->first();

        $accountId = match ($subtype) {
            AccountSubtype::AccountsReceivable => $currency?->ar_account_id,
            AccountSubtype::AccountsPayable => $currency?->ap_account_id,
            default => null,
        };

        if ($accountId === null) {
            throw new RuntimeException("No {$subtype->value} control account for {$currencyCode} on company {$company->id}. Enable the currency first.");
        }

        return Account::withoutGlobalScopes()->findOrFail($accountId);
    }
}
