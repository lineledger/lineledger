<?php

namespace App\Services\Insights\Contracts;

use App\Services\Insights\InsightCandidate;
use App\Services\Insights\NarratedInsight;
use App\Services\Insights\NarrationContext;

/**
 * Turns the day's ranked candidates into stored copy. Implementations must
 * always return something usable — the Claude narrator falls back to the
 * template narrator on any failure, so callers never handle errors.
 */
interface InsightNarrator
{
    /**
     * @param  non-empty-list<InsightCandidate>  $candidates  ranked best-first
     */
    public function narrate(array $candidates, NarrationContext $context): NarratedInsight;
}
