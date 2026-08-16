<?php

namespace App\Services\Currency;

use Carbon\CarbonImmutable;

/**
 * Immutable result of an {@see ExchangeRateHealth} check. Carries both a
 * human-readable summary (for the CLI and alert email) and a machine-readable
 * payload (for the /health/fx endpoint and structured logs).
 */
final class ExchangeRateHealthReport
{
    /**
     * @param  list<array{base: string, quote: string, fetched_at: ?string, age_hours: ?float, reason: string}>  $stalePairs
     */
    public function __construct(
        public readonly bool $healthy,
        public readonly string $reason,
        public readonly CarbonImmutable $checkedAt,
        public readonly int $maxAgeHours,
        public readonly int $expectedPairs,
        public readonly ?CarbonImmutable $newestFetchedAt,
        public readonly ?float $ageHours,
        public readonly array $stalePairs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->healthy ? 'ok' : 'stale',
            'healthy' => $this->healthy,
            'reason' => $this->reason,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'max_age_hours' => $this->maxAgeHours,
            'expected_pairs' => $this->expectedPairs,
            'newest_fetched_at' => $this->newestFetchedAt?->toIso8601String(),
            'age_hours' => $this->ageHours === null ? null : round($this->ageHours, 2),
            'stale_pairs' => $this->stalePairs,
        ];
    }
}
