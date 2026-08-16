<?php

namespace App\Services\Posting;

use App\Enums\PayrollAccount;
use App\Models\Account;
use App\Models\Company;
use RuntimeException;

/**
 * Resolves the system payroll accounts the pay-run posting recipe needs, by the
 * reserved code each {@see PayrollAccount} carries. Sibling of
 * {@see ControlAccountResolver}; throws (pointing at the backfill command) when
 * an account is missing rather than posting to the wrong place.
 */
class PayrollAccountResolver
{
    /** @var array<string, Account> */
    private array $cache = [];

    public function resolve(Company $company, PayrollAccount $account): Account
    {
        $key = $company->id.':'.$account->value;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $model = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $account->code())
            ->first();

        if ($model === null) {
            throw new RuntimeException(
                "Missing payroll account [{$account->code()} {$account->accountName()}] on company {$company->id}. Run `php artisan payroll:backfill-accounts`."
            );
        }

        return $this->cache[$key] = $model;
    }
}
