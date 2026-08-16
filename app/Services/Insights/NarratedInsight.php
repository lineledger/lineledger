<?php

namespace App\Services\Insights;

use App\Enums\InsightSource;

/**
 * A narrator's finished copy for the day: which candidate won, the words to
 * show, and whether Claude or the detector's template wrote them.
 */
final readonly class NarratedInsight
{
    public function __construct(
        public string $key,
        public string $headline,
        public string $body,
        public InsightSource $source,
    ) {}
}
