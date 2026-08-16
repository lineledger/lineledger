<?php

namespace App\Services\Insights\Detectors;

use App\Enums\AccountType;
use App\Enums\InsightCategory;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * The just-completed month set a revenue record for the trailing window
 * (up to 24 full months, trimmed to the company's posted history). Only
 * runs in the first days of a new month, and only speaks when there is
 * enough history that "best ever" actually means something.
 */
final class RecordRevenueMonthDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Trailing window of full months considered (including the completed one). */
    private const WINDOW_MONTHS = 24;

    /** A record needs this many positive-revenue history months behind it. */
    private const MIN_HISTORY_MONTHS = 6;

    public function __construct(private readonly ReportCalculator $calculator) {}

    public function key(): string
    {
        return 'record-revenue-month';
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
        // Cheapest gate first: only speak in the first three days of a month.
        if ($today->day > 3) {
            return [];
        }

        $completedStart = $today->startOfMonth()->subMonthNoOverflow();

        // Trim the window to the company's posted history so young books are
        // not padded with empty months. Detectors run without the
        // current_company binding — scope explicitly (lines via accounts).
        $firstDay = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $company->id)
            ->where('journal_lines.is_posted', true)
            ->min('journal_lines.entry_date');

        if ($firstDay === null) {
            return [];
        }

        $firstMonth = CarbonImmutable::parse((string) $firstDay)->startOfMonth();
        $earliest = $completedStart->subMonthsNoOverflow(self::WINDOW_MONTHS - 1);
        $windowStart = $firstMonth->greaterThan($earliest) ? $firstMonth : $earliest;
        $windowMonths = (int) $windowStart->diffInMonths($completedStart) + 1;

        if ($windowMonths < self::MIN_HISTORY_MONTHS + 1) {
            return []; // not enough history for a meaningful record
        }

        // Monthly income totals as differences of cumulative as-of aggregates:
        // <=-bounded sums compare exactly on both MySQL and SQLite, where a
        // between-bounded range would drop lines dated on the period start on
        // SQLite (Carbon bounds serialize with a time suffix).
        $cumulative = [];
        for ($i = 0; $i < $windowMonths; $i++) {
            $cumulative[$i] = $this->calculator->totalForTypeAsOf(
                $company,
                AccountType::Income,
                $completedStart->subMonthsNoOverflow($i)->endOfMonth(),
            );
        }
        $cumulative[$windowMonths] = $this->calculator->totalForTypeAsOf($company, AccountType::Income, $windowStart->subDay());

        $totals = []; // index 0 = the just-completed month, ascending = older
        for ($i = 0; $i < $windowMonths; $i++) {
            $totals[$i] = $cumulative[$i] - $cumulative[$i + 1];
        }

        $completed = $totals[0];
        $previousBest = $totals[1];
        $positiveHistoryMonths = 0;

        for ($i = 1; $i < $windowMonths; $i++) {
            $previousBest = max($previousBest, $totals[$i]);

            if ($totals[$i] > 0) {
                $positiveHistoryMonths++;
            }
        }

        if ($completed <= 0 || $completed <= $previousBest || $positiveHistoryMonths < self::MIN_HISTORY_MONTHS) {
            return [];
        }

        // ≥ 6 positive history months guarantee $previousBest > 0 here.
        $score = min(50 + (($completed - $previousBest) * 100 >= $previousBest * 20 ? 10 : 0), 60);

        $monthLabel = $completedStart->format('F');
        $windowLabel = $windowMonths === self::WINDOW_MONTHS
            ? __('2 years')
            : __(':count months', ['count' => $windowMonths]);
        $amountDisplay = $this->formatWhole($completed);
        $previousBestDisplay = $this->formatWhole($previousBest);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'month_label' => $monthLabel,
                'amount_cents' => $completed,
                'amount_display' => $amountDisplay,
                'window_months' => $windowMonths,
                'window_label' => $windowLabel,
                'previous_best_cents' => $previousBest,
                'previous_best_display' => $previousBestDisplay,
            ],
            headline: __(':month was your best revenue month in :window', [
                'month' => $monthLabel,
                'window' => $windowLabel,
            ]),
            body: __('You recorded :amount in revenue — ahead of the previous best of :previous_best. Nice momentum.', [
                'amount' => $amountDisplay,
                'previous_best' => $previousBestDisplay,
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
}
