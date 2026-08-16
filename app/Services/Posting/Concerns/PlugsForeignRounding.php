<?php

namespace App\Services\Posting\Concerns;

/**
 * Shared rounding-plug helper for foreign-currency posting. When each leg of an
 * entry is converted to home cents independently, the legs can sum to ±1¢ off the
 * converted document total. This nudges the largest leg so the entry balances
 * exactly in home cents (the GL invariant is a strict integer equality).
 *
 * @phpstan-type ConvertibleLeg array{account_id: int, class_id?: ?int, location_id?: ?int, foreign: int, home: int, memo: ?string}
 */
trait PlugsForeignRounding
{
    /**
     * Adjust the largest leg so the legs sum exactly to $targetHome. No-op when
     * already equal (always the case for the home currency, where every rate is 1).
     *
     * @param  list<array{account_id: int, class_id?: ?int, location_id?: ?int, foreign: int, home: int, memo: ?string}>  $legs
     */
    protected function applyRoundingPlug(array &$legs, int $targetHome): void
    {
        if ($legs === []) {
            return;
        }

        $plug = $targetHome - array_sum(array_column($legs, 'home'));

        if ($plug === 0) {
            return;
        }

        $largest = 0;

        foreach ($legs as $i => $leg) {
            if (abs($leg['home']) > abs($legs[$largest]['home'])) {
                $largest = $i;
            }
        }

        // Read-modify-write the whole leg (rather than mutating $legs[$i]['home']
        // in place) so PHPStan keeps the element's full shape instead of widening
        // it to array{home: int}.
        $leg = $legs[$largest];
        $leg['home'] += $plug;
        $legs[$largest] = $leg;
    }
}
