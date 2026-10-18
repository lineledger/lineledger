<?php

namespace App\Services\Insights\AI;

use App\Services\Insights\InsightCandidate;
use App\Support\Currency;

/**
 * The safety net that keeps AI-written copy honest: only display-ready values
 * ever cross the wire, whitespace is normalised, and every money amount in the
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
     * Every money amount ($, £, €…) in the output must appear verbatim (comma-insensitive)
     * among the chosen candidate's display values. Bare counts/percentages
     * are not enforced — harmless rewordings ("five days") would churn
     * fallbacks for no safety gain.
     */
    protected function amountsGrounded(string $text, InsightCandidate $chosen): bool
    {
        preg_match_all($this->moneyPattern(), $text, $matches);

        if ($matches[0] === []) {
            return true;
        }

        $offered = [];
        foreach ($chosen->facts as $value) {
            if (! is_string($value)) {
                continue;
            }

            preg_match_all($this->moneyPattern(), $value, $factMatches);
            foreach ($factMatches[0] as $token) {
                $offered[$this->moneyKey($token)] = true;
            }
        }

        foreach ($matches[0] as $token) {
            if (! isset($offered[$this->moneyKey($token)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A currency symbol from {@see Currency::symbols()} followed by an amount,
     * e.g. "$1,234", "£1,234.56", "£-80" or "CHF 950". A letter symbol ("R",
     * "kr", "CHF") must start a word, so "PR1,000" is not read as rand.
     */
    protected function moneyPattern(): string
    {
        $quote = fn (array $symbols): string => implode('|', array_map(fn (string $symbol): string => preg_quote($symbol, '/'), $symbols));
        $symbols = Currency::symbols();
        $words = array_filter($symbols, fn (string $symbol): bool => preg_match('/^\p{L}/u', $symbol) === 1);
        $signs = array_diff($symbols, $words);

        return '/(?:(?<![\p{L}\d])(?:'.$quote($words).')|'.$quote($signs).')\s?-?\d[\d,]*(?:\.\d{2})?/u';
    }

    /** Compare amounts comma- and space-insensitively. */
    protected function moneyKey(string $token): string
    {
        return (string) preg_replace('/[\s,]+/u', '', $token);
    }
}
