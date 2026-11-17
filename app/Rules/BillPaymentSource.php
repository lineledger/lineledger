<?php

namespace App\Rules;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that an id names an account a bill can be paid from, by the same rule on
 * the bill payment form and the API ({@see Account::scopeBillPaymentSources}).
 * CompanyScope keeps the lookup inside the current company. A payment may keep the
 * account it was saved with: statement lines and QuickBooks imports can use others,
 * and those payments have to stay editable.
 */
class BillPaymentSource implements ValidationRule
{
    public function __construct(private readonly int|string|null $savedAccountId = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->savedAccountId !== null && (int) $value === (int) $this->savedAccountId) {
            return;
        }

        if (! Account::query()->billPaymentSources()->whereKey($value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
