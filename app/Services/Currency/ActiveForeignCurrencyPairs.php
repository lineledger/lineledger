<?php

namespace App\Services\Currency;

use App\Console\Commands\FetchExchangeRates;
use App\Models\CompanyCurrency;

/**
 * Enumerates the distinct (foreign base => home quote) market pairs that should
 * have a global provider rate, across every multi-currency company.
 *
 * Shared by the daily fetch ({@see FetchExchangeRates}) and
 * the freshness check ({@see ExchangeRateHealth}) so the set of pairs we fetch is
 * exactly the set we monitor — a pair can never be healthy-by-omission.
 */
class ActiveForeignCurrencyPairs
{
    /**
     * @return list<array{base: string, quote: string}>
     */
    public function pairs(): array
    {
        $pairs = [];

        CompanyCurrency::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->where('is_home', false)
            ->with('company:id,currency_code,multicurrency_enabled')
            ->each(function (CompanyCurrency $currency) use (&$pairs): void {
                $company = $currency->company;

                if ($company === null || ! $company->isMulticurrencyEnabled()) {
                    return;
                }

                $base = mb_strtoupper((string) $currency->currency_code);
                $home = mb_strtoupper((string) $company->currency_code);

                if ($base === $home) {
                    return;
                }

                // Keyed so the same market pair across many companies collapses to one.
                $pairs[$base.'>'.$home] = ['base' => $base, 'quote' => $home];
            });

        return array_values($pairs);
    }
}
