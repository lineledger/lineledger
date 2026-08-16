<?php

namespace App\Services\Insights;

use App\Enums\InsightSource;
use App\Services\Insights\Contracts\InsightNarrator;

/**
 * The deterministic narrator: the top-ranked candidate's own template copy,
 * verbatim. This is the default experience — used when the company hasn't
 * opted in to AI narration, when the operator hasn't enabled/keyed it, and
 * as the Claude narrator's fallback on any failure.
 */
final class TemplateInsightNarrator implements InsightNarrator
{
    public function narrate(array $candidates, NarrationContext $context): NarratedInsight
    {
        $top = $candidates[0];

        return new NarratedInsight($top->key, $top->headline, $top->body, InsightSource::Template);
    }
}
