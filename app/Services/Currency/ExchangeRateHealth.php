<?php

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Decides whether the global provider exchange rates are fresh.
 *
 * A rate is "stale" when the newest API-sourced row for an expected pair was
 * fetched more than {@see config('services.exchange_rates.health.max_age_hours')}
 * hours ago (default 26). The daily `rates:fetch` job runs every day at 06:00 and
 * Frankfurter serves the last published rate on non-publishing days (weekends,
 * holidays), so a healthy `fetched_at` advances every day — which is why the age
 * threshold needs no weekend exemption: a stale timestamp always means the cron
 * stopped firing or the provider stopped responding, even over a long weekend.
 *
 * Companies with no active foreign currency have nothing to fetch, so the check
 * reports healthy rather than alarming on an empty system.
 */
class ExchangeRateHealth
{
    public function __construct(private ActiveForeignCurrencyPairs $pairs) {}

    public function check(?CarbonImmutable $now = null): ExchangeRateHealthReport
    {
        $now ??= CarbonImmutable::now();
        $maxAgeHours = max(1, (int) config('services.exchange_rates.health.max_age_hours', 26));
        $expected = $this->pairs->pairs();

        if ($expected === []) {
            return new ExchangeRateHealthReport(
                healthy: true,
                reason: 'No active foreign currencies configured; nothing to fetch.',
                checkedAt: $now,
                maxAgeHours: $maxAgeHours,
                expectedPairs: 0,
                newestFetchedAt: null,
                ageHours: null,
                stalePairs: [],
            );
        }

        $stale = [];
        $newest = null;

        foreach ($expected as $pair) {
            $fetchedAt = ExchangeRate::query()
                ->whereNull('company_id')
                ->where('source', ExchangeRate::SOURCE_API)
                ->where('base_code', $pair['base'])
                ->where('quote_code', $pair['quote'])
                ->orderByDesc('fetched_at')
                ->value('fetched_at');

            $fetchedAt = $fetchedAt !== null ? CarbonImmutable::parse($fetchedAt) : null;

            if ($fetchedAt === null) {
                $stale[] = [
                    'base' => $pair['base'],
                    'quote' => $pair['quote'],
                    'fetched_at' => null,
                    'age_hours' => null,
                    'reason' => 'never fetched',
                ];

                continue;
            }

            if ($newest === null || $fetchedAt->greaterThan($newest)) {
                $newest = $fetchedAt;
            }

            // Plain timestamp delta avoids Carbon version differences in diff sign.
            $ageHours = max(0.0, ($now->getTimestamp() - $fetchedAt->getTimestamp()) / 3600);

            if ($ageHours > $maxAgeHours) {
                $stale[] = [
                    'base' => $pair['base'],
                    'quote' => $pair['quote'],
                    'fetched_at' => $fetchedAt->toIso8601String(),
                    'age_hours' => round($ageHours, 2),
                    'reason' => sprintf('last fetched %.1fh ago (max %dh)', $ageHours, $maxAgeHours),
                ];
            }
        }

        $healthy = $stale === [];

        $overallAge = $newest !== null
            ? round(max(0.0, ($now->getTimestamp() - $newest->getTimestamp()) / 3600), 2)
            : null;

        $reason = $healthy
            ? sprintf('All %d foreign currency pair(s) refreshed within %dh.', count($expected), $maxAgeHours)
            : sprintf(
                '%d of %d foreign currency pair(s) stale (older than %dh): %s.',
                count($stale),
                count($expected),
                $maxAgeHours,
                implode(', ', array_map(static fn (array $p): string => $p['base'].'→'.$p['quote'], $stale)),
            );

        return new ExchangeRateHealthReport(
            healthy: $healthy,
            reason: $reason,
            checkedAt: $now,
            maxAgeHours: $maxAgeHours,
            expectedPairs: count($expected),
            newestFetchedAt: $newest,
            ageHours: $overallAge,
            stalePairs: $stale,
        );
    }
}
