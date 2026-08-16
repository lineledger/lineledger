<?php

namespace App\Services\Currency\Providers;

use App\Services\Currency\ExchangeRateProvider;
use Carbon\CarbonImmutable;

/**
 * Deterministic provider for tests and offline/local use. Returns rates from a
 * preset table (base => quote => rate); unknown pairs return nothing, which
 * surfaces as a MissingExchangeRateException upstream — the same path a real
 * provider takes when it has no data.
 */
class NullExchangeRateProvider implements ExchangeRateProvider
{
    /**
     * @param  array<string, array<string, string>>  $table  base => [quote => rate]
     */
    public function __construct(private array $table = []) {}

    /**
     * @param  array<string, string>  $rates  quote => rate, for the given base
     */
    public function set(string $base, array $rates): void
    {
        $this->table[mb_strtoupper($base)] = $rates;
    }

    public function rates(string $base, array $quotes, ?CarbonImmutable $date = null): array
    {
        $available = $this->table[mb_strtoupper($base)] ?? [];

        $result = [];

        foreach ($quotes as $quote) {
            $quote = mb_strtoupper($quote);

            if (isset($available[$quote])) {
                $result[$quote] = $available[$quote];
            }
        }

        return $result;
    }
}
