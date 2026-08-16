<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\Currency\ActiveForeignCurrencyPairs;
use App\Services\Currency\ExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchExchangeRates extends Command
{
    protected $signature = 'rates:fetch {--date= : Fetch for a specific date (Y-m-d); defaults to today}';

    protected $description = 'Fetch today\'s exchange rates for every active foreign currency and store them as global rates.';

    public function handle(ExchangeRateProvider $provider, ActiveForeignCurrencyPairs $activePairs): int
    {
        $date = $this->option('date') !== null
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::now();

        // Group the shared (foreign base => home quote) pairs into base => [quotes]
        // so each base is fetched once for all of its home quotes.
        $pairs = [];

        foreach ($activePairs->pairs() as $pair) {
            $pairs[$pair['base']][$pair['quote']] = true;
        }

        if ($pairs === []) {
            $this->info('No active foreign currencies to fetch.');

            return self::SUCCESS;
        }

        $stored = 0;

        foreach ($pairs as $base => $quotes) {
            $quoteCodes = array_keys($quotes);

            try {
                $rates = $provider->rates($base, $quoteCodes, $date);
            } catch (Throwable $e) {
                $this->error(sprintf('%s → [%s]: %s', $base, implode(',', $quoteCodes), $e->getMessage()));

                continue;
            }

            foreach ($rates as $quote => $rate) {
                ExchangeRate::query()->updateOrCreate(
                    [
                        'company_id' => null,
                        'base_code' => $base,
                        'quote_code' => $quote,
                        'rate_date' => $date->toDateString(),
                        'source' => ExchangeRate::SOURCE_API,
                    ],
                    ['rate' => (string) $rate, 'fetched_at' => now(), 'provider' => config('services.exchange_rates.driver')],
                );

                $stored++;
                $this->line(sprintf('%s → %s = %s', $base, $quote, $rate));
            }
        }

        $this->info("Stored {$stored} rate(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
