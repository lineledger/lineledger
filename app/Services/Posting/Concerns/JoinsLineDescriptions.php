<?php

namespace App\Services\Posting\Concerns;

/**
 * The memo for a GL leg built from document lines. Posters fold lines that share
 * an account and dimensions into one leg, so a leg can stand for several lines;
 * its memo is their descriptions — trimmed, blanks dropped, each distinct one
 * once, in line order — joined with "; ", so the ledger says what the money was
 * for. Null when no line has one.
 */
trait JoinsLineDescriptions
{
    /**
     * @param  list<?string>  $descriptions
     */
    protected function descriptionMemo(array $descriptions): ?string
    {
        $distinct = array_values(array_unique(array_filter(
            array_map(fn (?string $description): string => trim((string) $description), $descriptions),
            fn (string $description): bool => $description !== '',
        )));

        return $distinct === [] ? null : implode('; ', $distinct);
    }
}
