<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\CashflowForecaster;
use Carbon\CarbonImmutable;

/**
 * A softer companion to {@see CashflowRunwayDetector}: the committed forecast
 * stays positive but cash is projected to dip materially this quarter (at least
 * $1,000 and at least 40% of today's balance) as bills come due ahead of
 * collections. Informational, not urgent — and it yields to the runway alarm,
 * so the two never fire on the same day.
 */
final class CashflowShortfallDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Minimum projected drop to be worth surfacing. */
    private const MIN_DROP_CENTS = 100_000;

    public function __construct(private readonly CashflowForecaster $forecaster) {}

    public function key(): string
    {
        return 'cashflow-shortfall';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Fact;
    }

    /**
     * @return list<InsightCandidate>
     */
    public function detect(Company $company, CarbonImmutable $today): array
    {
        $forecast = $this->forecaster->forecast($company, 'week', 13, 0, $today);

        // A genuine below-zero breach is the runway detector's (Deadline) story.
        if ($forecast['breaches_floor']) {
            return [];
        }

        $opening = (int) $forecast['opening_cents'];
        $lowest = (int) $forecast['lowest_committed_cents'];
        $drop = $opening - $lowest;

        // Material decline only: ≥ $1,000 AND ≥ 40% of today's cash.
        if ($opening <= 0 || $drop < self::MIN_DROP_CENTS || $drop * 100 < $opening * 40) {
            return [];
        }

        $pct = (int) round($drop * 100 / $opening);

        $openingDisplay = $this->formatWhole($opening);
        $lowestDisplay = $this->formatWhole($lowest);
        $dropDisplay = $this->formatWhole($drop);

        $score = min(48 + ($drop * 100 >= $opening * 60 ? 6 : 0), 58);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'opening_cents' => $opening,
                'opening_display' => $openingDisplay,
                'lowest_cents' => $lowest,
                'lowest_display' => $lowestDisplay,
                'drop_cents' => $drop,
                'drop_display' => $dropDisplay,
                'pct_drop' => $pct,
            ],
            headline: __('Cash is set to dip about :pct% this quarter', ['pct' => $pct]),
            body: __('Bills coming due outpace expected collections — your committed balance bottoms out near :lowest, about :drop below today\'s :opening.', [
                'lowest' => $lowestDisplay,
                'drop' => $dropDisplay,
                'opening' => $openingDisplay,
            ]),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.cash-flow-forecast', 'label' => __('View forecast')];
    }
}
