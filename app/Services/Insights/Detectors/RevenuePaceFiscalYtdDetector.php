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
 * Fiscal-year-to-date revenue is meaningfully ahead of (or behind) the same
 * span of the prior fiscal year. Waits until the year is at least 60 days
 * old so early-year noise doesn't read as a trend.
 */
final class RevenuePaceFiscalYtdDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Days into the fiscal year before a pace comparison means anything. */
    private const MIN_FISCAL_YEAR_AGE_DAYS = 60;

    public function __construct(private readonly ReportCalculator $calculator) {}

    public function key(): string
    {
        return 'revenue-pace-fiscal-ytd';
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
        $fyStart = $this->calculator->fiscalYearStart($company, $today);

        // Cheapest gate first: no GL queries until the year is old enough.
        if ($fyStart->diffInDays($today) < self::MIN_FISCAL_YEAR_AGE_DAYS) {
            return [];
        }

        $ytd = $this->incomeTotal($company, $fyStart, $today);
        $priorYtd = $this->incomeTotal($company, $fyStart->subYear(), $today->subYear());

        if ($priorYtd <= 0) {
            return []; // no prior-year base to pace against
        }

        $diff = $ytd - $priorYtd;

        // Integer-exact gate: |change| ≥ 10% of the prior span.
        if (abs($diff) * 100 < $priorYtd * 10) {
            return [];
        }

        $pct = (int) round($diff * 100 / $priorYtd);
        $direction = $diff > 0 ? 'ahead' : 'behind';
        $score = min(45 + (abs($diff) * 100 >= $priorYtd * 25 ? 10 : 0), 55);

        $ytdDisplay = $this->formatWhole($ytd);
        $priorYtdDisplay = $this->formatWhole($priorYtd);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'ytd_cents' => $ytd,
                'ytd_display' => $ytdDisplay,
                'prior_ytd_cents' => $priorYtd,
                'prior_ytd_display' => $priorYtdDisplay,
                'pct_change' => $pct,
                'direction' => $direction,
            ],
            headline: $direction === 'ahead'
                ? __('Revenue is running :pct% ahead of last year', ['pct' => abs($pct)])
                : __('Revenue is tracking :pct% behind last year', ['pct' => abs($pct)]),
            body: __("Fiscal year to date you've recorded :ytd, versus :prior by this point last year.", [
                'ytd' => $ytdDisplay,
                'prior' => $priorYtdDisplay,
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
