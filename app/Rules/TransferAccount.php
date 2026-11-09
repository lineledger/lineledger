<?php

namespace App\Rules;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that an id names an account a transfer can use, by the same rule on the
 * transfer form and the API ({@see Account::scopeTransferAccounts}). CompanyScope
 * keeps the lookup inside the current company, so another organization's account
 * fails too. A since-deactivated account still passes, so editing a saved transfer
 * doesn't get stuck on it.
 */
class TransferAccount implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Account::query()->transferAccounts()->whereKey($value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
