<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a string can be parsed by {@see Money::fromString} so that
 * persistence paths never hand unparseable input to the strict parser and 500.
 * An empty string passes (use alongside `required`/`nullable` to control that);
 * anything non-empty must parse cleanly, e.g. "100", "1,234.56", "-12.34".
 */
class MoneyString implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((string) $value !== '' && Money::tryFromString((string) $value) === null) {
            $fail(__('Enter a valid amount, e.g. 100.00.'));
        }
    }
}
