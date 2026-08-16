<?php

namespace App\Services\Insights\AI;

use App\Services\Insights\InsightCandidate;

/**
 * The safety net that keeps AI-written copy honest: only display-ready values
 * ever cross the wire, whitespace is normalised, and every dollar amount in the
 * model's output must appear verbatim among the source facts or the output is
 * rejected. Shared by {@see ClaudeInsightNarrator} (one short insight) and any
 * longer-form narrator (e.g. the monthly summary) so the "the model never does
 * arithmetic" guarantee is defined once.
 */
trait GroundsNarratedAmounts
{
    /**
     * Only display-ready values cross the wire: pre-formatted `*_display`
     * strings and plain scalars (counts, percentages, labels). Raw `*_cents`
     * integers stay home so the model is never tempted to do arithmetic.
     *
     * @return array<string, int|float|string|bool|null>
     */
    protected function transportFacts(InsightCandidate $candidate): array
    {
        return array_filter(
            $candidate->facts,
            fn ($value, string $key): bool => ! str_ends_with($key, '_cents'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Collapse whitespace/newlines; non-scalars become empty (→ rejected). */
    protected function squish(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Every $-amount in the output must appear verbatim (comma-insensitive)
     * among the chosen candidate's display values. Bare counts/percentages
     * are not enforced — harmless rewordings ("five days") would churn
     * fallbacks for no safety gain.
     */
    protected function amountsGrounded(string $text, InsightCandidate $chosen): bool
    {
        preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $text, $matches);

        if ($matches[0] === []) {
            return true;
        }

        $offered = [];
        foreach ($chosen->facts as $value) {
            if (! is_string($value)) {
                continue;
            }

            preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $value, $factMatches);
            foreach ($factMatches[0] as $token) {
                $offered[str_replace(',', '', $token)] = true;
            }
        }

        foreach ($matches[0] as $token) {
            if (! isset($offered[str_replace(',', '', $token)])) {
                return false;
            }
        }

        return true;
    }
}
