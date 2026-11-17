<?php

namespace App\Rules;

use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that an id names an account a customer's payment can land in, by the same
 * rule on the receipt and sales receipt forms and the API
 * ({@see Account::scopePaymentDepositAccounts}). CompanyScope keeps the lookup inside
 * the current company. A document may keep the account it was saved with: Stripe
 * receipts and QuickBooks imports can use others, such as a clearing account, and
 * those documents have to stay editable.
 */
class PaymentDepositAccount implements ValidationRule
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

        if (! Account::query()->paymentDepositAccounts()->whereKey($value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
