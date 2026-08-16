<?php

namespace App\Services\Insights\Detectors;

use App\Enums\AccountType;
use App\Enums\InsightCategory;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * Month-to-date revenue versus the same days of the same month last year —
 * the season-aware sibling of the dashboard's month-over-month KPI. Uses
 * the same same-days-elapsed span technique as the dashboard's
 * netIncomeMtd(), so a mid-month read never compares 10 days against 31.
 */
final class SameMonthLastYearDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Too early in the month and the MTD sample is mostly noise. */
    private const MIN_DAY_OF_MONTH = 10;

    public function __construct(private readonly ReportCalculator $calculator) {}

    public function key(): string
    {
        return 'same-month-last-year';
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
        // Cheapest gate first: wait for a meaningful month-to-date sample.
        if ($today->day < self::MIN_DAY_OF_MONTH) {
            return [];
        }

        $monthStart = $today->startOfMonth();
        $mtd = $this->incomeTotal($company, $monthStart, $today);

        // Same-days-elapsed prior span, mirroring the dashboard's netIncomeMtd().
        $daysElapsed = $monthStart->diffInDays($today);
        $priorStart = $monthStart->subYear();
        $priorEnd = $priorStart->addDays($daysElapsed);

        $prior = $this->incomeTotal($company, $priorStart, $priorEnd);

        if ($prior <= 0) {
            return []; // no last-year base to compare against
        }

        $diff = $mtd - $prior;

        // Integer-exact gate: |change| ≥ 15% of last year's span.
        if (abs($diff) * 100 < $prior * 15) {
            return [];
        }

        $pct = (int) round($diff * 100 / $prior);
        $direction = $diff > 0 ? 'ahead' : 'behind';
        $score = min(40 + (abs($diff) * 100 >= $prior * 30 ? 5 : 0), 45);

        $monthLabel = $today->format('F');
        $mtdDisplay = $this->formatWhole($mtd);
        $priorDisplay = $this->formatWhole($prior);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'month_label' => $monthLabel,
                'mtd_cents' => $mtd,
                'mtd_display' => $mtdDisplay,
                'prior_cents' => $prior,
                'prior_display' => $priorDisplay,
                'pct_change' => $pct,
                'direction' => $direction,
            ],
            headline: $direction === 'ahead'
                ? __('This :month is :pct% ahead of last :month', ['month' => $monthLabel, 'pct' => abs($pct)])
                : __('This :month is :pct% behind last :month', ['month' => $monthLabel, 'pct' => abs($pct)]),
            body: __("Month to date you've recorded :mtd in revenue; by this point last :month it was :prior.", [
                'mtd' => $mtdDisplay,
                'month' => $monthLabel,
                'prior' => $priorDisplay,
            ]),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return $this->isNonProfit($company)
            ? ['route' => 'reports.statement-of-operations', 'label' => __('View statement of operations')]
            : ['route' => 'reports.income-statement', 'label' => __('View income statement')];
    }

    /**
     * Larastan types the organization_type cast as its backing string, so read
     * the attribute and narrow on the enum instance instead of the (baselined
     * elsewhere) `organization_type?->isNonProfit()` shorthand.
     */
    private function isNonProfit(Company $company): bool
    {
        $type = $company->getAttribute('organization_type');

        return $type instanceof OrganizationType && $type->isNonProfit();
    }

    /**
     * Income total for an inclusive [$start, $end] day span, as the difference
     * of two cumulative {@see ReportCalculator::totalForTypeAsOf()} sums.
     * <=-bounded aggregates compare exactly on both MySQL and SQLite, where a
     * between-bounded range would drop lines dated on the span start on SQLite
     * (Carbon bounds serialize with a time suffix that text-compares after a
     * stored Y-m-d date).
     */
    private function incomeTotal(Company $company, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->calculator->totalForTypeAsOf($company, AccountType::Income, $end)
            - $this->calculator->totalForTypeAsOf($company, AccountType::Income, $start->subDay());
    }
}
