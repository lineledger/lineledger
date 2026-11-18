<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared line validation for credit memos. A line's quantity may be negative:
 * a credit memo that refunds one service and re-charges the customer for a
 * substituted one records the re-charge as a negative-quantity line, so Sales
 * by Item counts that item as sold and the line posts as a credit to its
 * account. A quantity of zero is refused, and the lines as a whole must still
 * credit the customer something — CreditMemoPoster refuses a total of zero or
 * less, and its exception would mislead an integrator who sent one.
 */
final class CreditMemoLineRules
{
    /**
     * Rules for `lines.*.quantity`: within ±1,000,000 and not zero.
     *
     * @return array<int, mixed>
     */
    public static function quantity(): array
    {
        return [
            'required',
            'numeric',
            'min:-1000000',
            'max:1000000',
            static function (string $attribute, mixed $value, Closure $fail): void {
                // not_in:0 compares strings, so "0.00" would get past it. Compare
                // at the four decimals the line stores, the same scaling
                // TaxCalculator::line() applies.
                if (is_numeric($value) && (int) round(((float) $value) * 10000) === 0) {
                    $fail('The :attribute must not be zero.');
                }
            },
        ];
    }

    /**
     * Refuse lines that net to zero or to a debit. Like
     * {@see InvoiceLineRules::validatePositiveTotal()} this checks the pre-tax
     * subtotal, so it needs no tax-code lookups; a memo whose subtotal is
     * positive but whose tax pulls the total to zero or below is still refused
     * by CreditMemoPoster when it posts.
     *
     * @param  array<int, mixed>  $lines
     */
    public static function validatePositiveTotal(Validator $validator, array $lines): void
    {
        // A malformed line already has a precise field error; don't stack a
        // misleading total error on top of it.
        if ($validator->errors()->hasAny(['lines', 'lines.*'])) {
            return;
        }

        if (InvoiceLineRules::subtotalCents($lines) <= 0) {
            $validator->errors()->add('lines', 'The credit memo total must be greater than zero.');
        }
    }
}
