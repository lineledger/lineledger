<?php

namespace App\Services\Insights\Detectors;

use App\Enums\AccountSubtype;
use App\Enums\InsightCategory;
use App\Models\Account;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * Cash on hand moved meaningfully over the last 30 days. Mirrors the
 * dashboard's "Cash on hand" KPI: every Bank + Undeposited Funds account,
 * summed via {@see ReportCalculator::balanceAsOf()} today versus 30 days
 * ago. A drop scores higher than a rise — falling cash is the thing an
 * owner most wants flagged.
 */
final class CashTrendDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Ignore moves under $500 — noise on small balances. */
    private const MIN_DELTA_CENTS = 50_000;

    public function __construct(private readonly ReportCalculator $calculator) {}

    public function key(): string
    {
        return 'cash-trend-30d';
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
        // Detectors run without the current_company binding — scope explicitly.
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::UndepositedFunds->value])
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $priorDay = $today->subDays(30);

        $current = 0;
        $prior = 0;

        foreach ($accounts as $account) {
            $current += $this->calculator->balanceAsOf($account, $today);
            $prior += $this->calculator->balanceAsOf($account, $priorDay);
        }

        if ($prior <= 0) {
            return []; // no positive base to compute a percentage against
        }

        $delta = $current - $prior;

        // Integer-exact gates: |delta| ≥ $500 and |delta| ≥ 10% of the prior balance.
        if (abs($delta) < self::MIN_DELTA_CENTS || abs($delta) * 100 < $prior * 10) {
            return [];
        }

        $pct = (int) round($delta * 100 / $prior);
        $direction = $delta > 0 ? 'up' : 'down';

        $score = min(($direction === 'up' ? 35 : 55) + (abs($delta) * 100 >= $prior * 25 ? 5 : 0), 60);

        $currentDisplay = $this->formatWhole($current);
        $deltaDisplay = $this->formatWhole(abs($delta));

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'current_cents' => $current,
                'current_display' => $currentDisplay,
                'prior_cents' => $prior,
                'prior_display' => $this->formatWhole($prior),
                'delta_cents' => abs($delta),
                'delta_display' => $deltaDisplay,
                'pct_change' => $pct,
                'direction' => $direction,
            ],
            headline: $direction === 'up'
                ? __('Cash is up :pct% over the last 30 days', ['pct' => abs($pct)])
                : __('Cash is down :pct% from a month ago', ['pct' => abs($pct)]),
            body: $direction === 'up'
                ? __('Your bank balances total :current, :delta more than a month ago.', ['current' => $currentDisplay, 'delta' => $deltaDisplay])
                : __('Bank balances now total :current, :delta lower than 30 days ago. The cash-flow report shows where it went.', ['current' => $currentDisplay, 'delta' => $deltaDisplay]),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.cash-flow', 'label' => __('View cash flow')];
    }
}
