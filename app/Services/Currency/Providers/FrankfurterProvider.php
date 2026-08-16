<?php

namespace App\Services\Currency\Providers;

use App\Services\Currency\ExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Free, key-less ECB-published rates via Frankfurter (frankfurter.dev). Rates are
 * weekday-only and limited to the major currencies the ECB publishes — adequate
 * as the default provider. The base URL is configurable so a paid provider can
 * be swapped in without touching callers.
 */
class FrankfurterProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly string $baseUrl = 'https://api.frankfurter.dev/v1',
        private readonly int $timeout = 10,
    ) {}

    public function rates(string $base, array $quotes, ?CarbonImmutable $date = null): array
    {
        if ($quotes === []) {
            return [];
        }

        $path = $date !== null ? $date->toDateString() : 'latest';

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->get("{$this->baseUrl}/{$path}", [
                'base' => mb_strtoupper($base),
                'symbols' => implode(',', array_map('mb_strtoupper', $quotes)),
            ])
            ->throw();

        $rates = (array) $response->json('rates', []);

        $result = [];

        foreach ($rates as $code => $rate) {
            $result[mb_strtoupper((string) $code)] = (string) $rate;
        }

        return $result;
    }
}
