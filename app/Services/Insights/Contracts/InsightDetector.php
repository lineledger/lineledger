<?php

namespace App\Services\Insights\Contracts;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;

/**
 * One self-contained "thing worth telling the owner" — its trigger
 * conditions, its figures, and its own template copy. Detectors are listed
 * in InsightDetectorRegistry; keys are stored on daily_insights.type, so a
 * shipped detector's key must never change.
 */
interface InsightDetector
{
    /** Stable kebab-case key, stored on daily_insights.type. */
    public function key(): string;

    public function category(): InsightCategory;

    /**
     * Zero, one, or several candidates for this company-day. Must be
     * deterministic for (company, today) and silent (empty list) when the
     * trigger conditions aren't met. See InsightCandidate for the privacy
     * rules on facts. Runs without the `current_company` binding — scope
     * every query explicitly.
     *
     * @return list<InsightCandidate>
     */
    public function detect(Company $company, CarbonImmutable $today): array;

    /**
     * Where the card/history row sends the user, or null for no CTA. Routes
     * are company-scoped; the caller supplies the {company} parameter.
     *
     * @return array{route: string, label: string}|null
     */
    public function cta(Company $company): ?array;
}
