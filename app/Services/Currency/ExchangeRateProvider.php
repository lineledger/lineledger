<?php

namespace App\Services\Currency;

use Carbon\CarbonImmutable;

/**
 * Fetches raw market rates from an external source. Implementations do network
 * I/O only — persistence, company overrides, and as-of resolution all live in
 * {@see ExchangeRateService}.
 */
interface ExchangeRateProvider
{
    /**
     * Rates expressed as "quote units per 1 base unit". A null date means the
     * latest available rate.
     *
     * @param  list<string>  $quotes
     * @return array<string, string> quote code => decimal rate string
     */
    public function rates(string $base, array $quotes, ?CarbonImmutable $date = null): array;
}
