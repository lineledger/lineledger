<?php

namespace App\Services\Posting\Concerns;

use App\Models\TaxCode;

/**
 * Helpers for posting a line's taxes. A line can carry two taxes (a primary and
 * a secondary, e.g. GST + PST/QST); each posts independently to its own agency's
 * payable account and keeps its own recoverability. Callers pass the line's tax
 * codes paired with the cents already computed and stored on the line, so the
 * posted amounts match the document exactly.
 */
trait SplitsLineTax
{
    /**
     * Accumulate tax cents by payable account across a line's taxes.
     *
     * @param  array<int, int>  $grouped  payable_account_id => cents (mutated)
     * @param  list<array{0: ?TaxCode, 1: int}>  $taxes  [taxCode, cents] pairs
     */
    protected function addTaxesByPayable(array &$grouped, array $taxes, bool $recoverableOnly = false): void
    {
        foreach ($taxes as [$code, $cents]) {
            if (! $code || $cents === 0) {
                continue;
            }

            if ($recoverableOnly && ! $code->is_recoverable) {
                continue;
            }

            $payableAccountId = $code->agency?->payable_account_id;

            if (! $payableAccountId) {
                continue;
            }

            $grouped[$payableAccountId] = ($grouped[$payableAccountId] ?? 0) + $cents;
        }
    }

    /**
     * The portion of a line's tax that is not an input credit and so must be
     * grossed up into the expense/cost.
     *
     * @param  list<array{0: ?TaxCode, 1: int}>  $taxes  [taxCode, cents] pairs
     */
    protected function nonRecoverableTax(array $taxes): int
    {
        $sum = 0;

        foreach ($taxes as [$code, $cents]) {
            if ($code && ! $code->is_recoverable) {
                $sum += $cents;
            }
        }

        return $sum;
    }
}
