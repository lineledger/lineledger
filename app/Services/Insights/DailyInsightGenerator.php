<?php

namespace App\Services\Insights;

use App\Enums\InsightCategory;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Contracts\InsightNarrator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Computes and stores one company's insight for one (company-local) day.
 * Detection and selection are deterministic; narration is AI only when the
 * company has opted in AND the operator bound the Claude narrator —
 * otherwise the template narrator writes the copy. Idempotent per
 * (company, day) via the unique daily_insights index. Runs without the
 * `current_company` binding (console --sync), so every tenant query here is
 * explicit.
 */
final class DailyInsightGenerator
{
    /** Covers the longest anti-repeat window (InsightCategory::Fact, 21 days). */
    private const LOOKBACK_DAYS = 21;

    /** How many recent insights the AI narrator sees, for phrasing variety. */
    private const RECENT_FOR_CONTEXT = 7;

    /**
     * @param  list<InsightDetector>  $detectors
     */
    public function __construct(
        private readonly InsightNarrator $narrator,
        private readonly TemplateInsightNarrator $template,
        private readonly InsightSelector $selector,
        private readonly array $detectors,
    ) {}

    public function generate(Company $company, CarbonImmutable $now): ?DailyInsight
    {
        $date = $now->toDateString();

        if (($existing = $this->existing($company, $date)) !== null) {
            return $existing;
        }

        $candidates = [];
        foreach ($this->detectors as $detector) {
            $candidates = [...$candidates, ...$detector->detect($company, $now)];
        }

        if ($candidates === []) {
            return null; // young/empty books skip the day quietly; the card hides
        }

        $recent = $this->recent($company, $now);

        $ranked = $this->selector->rank(
            $candidates,
            array_map(fn (array $row): array => ['type' => $row['type'], 'insight_date' => $row['insight_date']], $recent),
            $this->categoryShownOn($recent, $now->subDay()->toDateString()),
            $company->id,
            $now,
        );

        if ($ranked === []) {
            return null; // everything suppressed by anti-repeat
        }

        // The per-company opt-in is checked here, at call time — the bound
        // narrator is operator-level (enabled + keyed, or template).
        $narrator = $company->insightsAiNarrationEnabled() ? $this->narrator : $this->template;

        $offered = array_slice($ranked, 0, max(1, (int) config('insights.ai.max_candidates', 3)));

        $narrated = $narrator->narrate($offered, $this->contextFor($company, $now, $recent));

        $winner = null;
        foreach ($ranked as $candidate) {
            if ($candidate->key === $narrated->key) {
                $winner = $candidate;
                break;
            }
        }

        try {
            return DailyInsight::query()->create([
                'company_id' => $company->id, // explicit — preserved when current_company isn't bound
                'insight_date' => $date,
                'type' => $narrated->key,
                'source' => $narrated->source,
                'headline' => $narrated->headline,
                'body' => $narrated->body,
                'facts' => $winner?->facts,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Raced another run of the same day; the unique index backstops the
            // check-then-create above.
            return $this->existing($company, $date);
        }
    }

    private function existing(Company $company, string $date): ?DailyInsight
    {
        return DailyInsight::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('insight_date', $date)
            ->first();
    }

    /**
     * @return list<array{type: string, insight_date: string, headline: string}> newest first
     */
    private function recent(Company $company, CarbonImmutable $now): array
    {
        return DailyInsight::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('insight_date', '>=', $now->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->orderByDesc('insight_date')
            ->get(['type', 'insight_date', 'headline'])
            ->map(fn (DailyInsight $row): array => [
                'type' => $row->type,
                'insight_date' => $row->insight_date->toDateString(),
                'headline' => $row->headline,
            ])
            ->all();
    }

    /**
     * @param  list<array{type: string, insight_date: string, headline: string}>  $recent
     */
    private function categoryShownOn(array $recent, string $date): ?InsightCategory
    {
        foreach ($recent as $row) {
            if ($row['insight_date'] === $date) {
                return InsightDetectorRegistry::categoryFor($row['type']);
            }
        }

        return null;
    }

    /**
     * @param  list<array{type: string, insight_date: string, headline: string}>  $recent
     */
    private function contextFor(Company $company, CarbonImmutable $now, array $recent): NarrationContext
    {
        // Larastan can't see Company's enum cast, so narrow explicitly (the
        // cast yields OrganizationType|null at runtime).
        $orgType = $company->getAttribute('organization_type');
        $orgType = $orgType instanceof OrganizationType ? $orgType : null;

        return new NarrationContext(
            today: $now->toDateString(),
            company: [
                'organization_type' => $orgType?->value,
                'is_non_profit' => (bool) $orgType?->isNonProfit(),
                'tracks_membership' => $company->tracksMembership(),
                'home_currency' => (string) $company->currency_code,
            ],
            recentInsights: array_map(
                fn (array $row): array => ['date' => $row['insight_date'], 'key' => $row['type'], 'headline' => $row['headline']],
                array_slice($recent, 0, self::RECENT_FOR_CONTEXT),
            ),
        );
    }
}
