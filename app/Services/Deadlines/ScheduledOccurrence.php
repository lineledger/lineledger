<?php

namespace App\Services\Deadlines;

use Carbon\CarbonImmutable;

/**
 * One occurrence a {@see DeadlineRule} produces, before it is stored.
 */
final readonly class ScheduledOccurrence
{
    public function __construct(
        /** 1-based position in the series (what "after N occurrences" counts). */
        public int $sequence,
        /** Stable identity: the period start, or the due date when there is no period. */
        public string $key,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public CarbonImmutable $unadjustedDueDate,
        public CarbonImmutable $dueDate,
    ) {}
}
