<?php

namespace App\Services\Currency;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * Resolves and stores exchange rates. Rates are "home units per 1 foreign unit"
 * (base = foreign, quote = home), so the value returned plugs straight into
 * {@see Currency::toHomeCents()}.
 *
 * Resolution order for rateFor():
 *   1. company manual override, most recent on or before the date
 *   2. global provider rate, most recent on or before the date
 *   3. an on-demand provider fetch for that exact date (cached as a global row)
 *   4. throw MissingExchangeRateException so the UI can force a manual entry
 */
class ExchangeRateService
{
    public function __construct(private readonly ExchangeRateProvider $provider) {}

    /**
     * Home units per 1 unit of $foreign, as of $asOf. Returns "1" when $foreign
     * is already the home currency.
     */
    public function rateFor(Company $company, string $foreign, CarbonImmutable $asOf, ?string $home = null): string
    {
        $home = mb_strtoupper($home ?? (string) $company->currency_code);
        $foreign = mb_strtoupper($foreign);

        if ($foreign === $home) {
            return '1';
        }

        $override = $this->lookupStored($company->id, $foreign, $home, $asOf);

        if ($override !== null) {
            return $override;
        }

        $global = $this->lookupStored(null, $foreign, $home, $asOf);

        if ($global !== null) {
            return $global;
        }

        $fetched = $this->fetchAndStore($foreign, $home, $asOf);

        if ($fetched !== null) {
            return $fetched;
        }

        throw MissingExchangeRateException::for($foreign, $home, $asOf->toDateString());
    }

    /**
     * Record a manual rate for one company, overriding the global rate on lookup.
     */
    public function setManualRate(Company $company, string $foreign, string $rate, CarbonImmutable $date, ?string $home = null): ExchangeRate
    {
        $home = mb_strtoupper($home ?? (string) $company->currency_code);

        return ExchangeRate::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'base_code' => mb_strtoupper($foreign),
                'quote_code' => $home,
                'rate_date' => $date->toDateString(),
                'source' => ExchangeRate::SOURCE_MANUAL,
            ],
            ['rate' => $rate],
        );
    }

    /**
     * Most recent stored rate for the pair on or before $asOf, or null. A null
     * $companyId targets the global provider rows.
     */
    private function lookupStored(?int $companyId, string $foreign, string $home, CarbonImmutable $asOf): ?string
    {
        $row = ExchangeRate::query()
            ->when(
                $companyId === null,
                fn ($query) => $query->whereNull('company_id'),
                fn ($query) => $query->where('company_id', $companyId),
            )
            ->where('base_code', $foreign)
            ->where('quote_code', $home)
            ->where('rate_date', '<=', $asOf->toDateString())
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();

        return $row?->rate !== null ? (string) $row->rate : null;
    }

    /**
     * Fetch the pair from the provider for $asOf and cache it as a global row.
     * Returns null when the provider has no data (handled as a missing rate).
     */
    private function fetchAndStore(string $foreign, string $home, CarbonImmutable $asOf): ?string
    {
        $rates = $this->provider->rates($foreign, [$home], $asOf);

        if (! isset($rates[$home])) {
            return null;
        }

        $rate = (string) $rates[$home];

        ExchangeRate::query()->updateOrCreate(
            [
                'company_id' => null,
                'base_code' => $foreign,
                'quote_code' => $home,
                'rate_date' => $asOf->toDateString(),
                'source' => ExchangeRate::SOURCE_API,
            ],
            [
                'rate' => $rate,
                'fetched_at' => now(),
            ],
        );

        return $rate;
    }
}
