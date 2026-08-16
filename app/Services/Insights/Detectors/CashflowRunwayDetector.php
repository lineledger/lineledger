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
 * The committed cash forecast — opening cash plus open invoices and bills by
 * their due dates — is projected to fall below zero within the 13-week horizon.
 * This is the runway alarm: an urgent, deadline-style heads-up so an owner can
 * chase a receivable or defer a payable before the books go red. Skips books
 * that hold no cash today (a brand-new company at zero isn't "running low").
 */
final class CashflowRunwayDetector implements InsightDetector
{
    use FormatsInsightFacts;

    public function __construct(private readonly CashflowForecaster $forecaster) {}

    public function key(): string
    {
        return 'cashflow-runway';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Deadline;
    }

    /**
     * @return list<InsightCandidate>
     */
    public function detect(Company $company, CarbonImmutable $today): array
    {
        $forecast = $this->forecaster->forecast($company, 'week', 13, 0, $today);

        if (! $forecast['breaches_floor'] || $forecast['first_breach_date'] === null) {
            return [];
        }

        $opening = (int) $forecast['opening_cents'];

        if ($opening <= 0) {
            return []; // no cash on hand today — not a runway story
        }

        $lowest = (int) $forecast['lowest_committed_cents'];
        $breachDate = CarbonImmutable::parse($forecast['first_breach_date']);

        $openingDisplay = $this->formatWhole($opening);
        $lowestDisplay = $this->formatWhole($lowest);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: 92,
            facts: [
                'opening_cents' => $opening,
                'opening_display' => $openingDisplay,
                'lowest_cents' => $lowest,
                'lowest_display' => $lowestDisplay,
                'breach_date' => $breachDate->toDateString(),
                'breach_day' => $this->formatDay($breachDate),
            ],
            headline: __('Cash may run short around :date', ['date' => $this->formatDay($breachDate)]),
            body: __('Open invoices and bills project your cash falling from :opening to about :lowest by then. Collecting an overdue invoice or deferring a bill would close the gap.', [
                'opening' => $openingDisplay,
                'lowest' => $lowestDisplay,
            ]),
            urgent: true,
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
