<?php

namespace App\Actions\Fundraising;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

/**
 * Ensures a company has the income + liability accounts the fundraising module
 * needs: a donation revenue account, a grant revenue account, and a deferred /
 * restricted-grants liability. Non-profit charts already seed these (4000/4100/2500),
 * so this is a no-op there; it backfills for any other company that enables the
 * feature. Idempotent — matches existing accounts by name before creating, and
 * never creates a duplicate concept. Returns the number of accounts created.
 */
final class EnsureFundraisingAccounts
{
    public function handle(Company $company): int
    {
        $created = 0;

        $created += $this->ensure($company, AccountSubtype::Income, ['donation', 'contribution'], '4900', 'Donations & Contributions');
        $created += $this->ensure($company, AccountSubtype::Income, ['grant'], '4910', 'Grant Revenue');
        $created += $this->ensure($company, AccountSubtype::CurrentLiability, ['deferred'], '2500', 'Deferred / Restricted Grants');

        return $created;
    }

    /**
     * Create the account at the preferred code (or the next free one) unless an
     * active account of the same type already matches one of the name keywords.
     *
     * @param  list<string>  $keywords
     */
    protected function ensure(Company $company, AccountSubtype $subtype, array $keywords, string $preferredCode, string $name): int
    {
        $type = $subtype->type();

        $existing = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', $type->value)
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('name', 'like', '%'.$keyword.'%');
                }
            })
            ->exists();

        if ($existing) {
            return 0;
        }

        Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $this->freeCode($company, $preferredCode),
            'name' => $name,
            'type' => $type,
            'subtype' => $subtype,
            'normal_balance' => $type->normalBalance(),
            'is_active' => true,
        ]);

        return 1;
    }

    protected function freeCode(Company $company, string $preferred): string
    {
        $code = (int) $preferred;

        while (Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', (string) $code)->exists()) {
            $code++;
        }

        return (string) $code;
    }
}
