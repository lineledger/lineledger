<?php

namespace App\Services\Insights;

/**
 * Non-candidate context handed to a narrator: the company-local date, a few
 * org-shape flags (so phrasing can say "members" instead of "customers"),
 * and the last few stored insights so wording varies day to day. Carries the
 * same privacy constraints as InsightCandidate — flags and dates only, no
 * names.
 */
final readonly class NarrationContext
{
    /**
     * @param  array<string, mixed>  $company
     * @param  list<array{date: string, key: string, headline: string}>  $recentInsights
     */
    public function __construct(
        public string $today,
        public array $company,
        public array $recentInsights,
    ) {}
}
