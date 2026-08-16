<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Imported statement lines sitting in Unmatched/Suggested pile up quietly and
 * stale the books. Fires once ten or more lines are waiting, nudging the owner
 * toward the reconcile screen — especially when suggested matches only need a
 * click to confirm.
 */
final class UnmatchedBankLinesDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_PENDING_LINES = 10;

    public function key(): string
    {
        return 'unmatched-bank-lines';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Hygiene;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $unmatched = $this->pendingLines($company)
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->count();

        $suggested = $this->pendingLines($company)
            ->where('match_status', StatementLineMatchStatus::Suggested->value)
            ->count();

        $total = $unmatched + $suggested;

        if ($total < self::MIN_PENDING_LINES) {
            return [];
        }

        $oldest = CarbonImmutable::parse((string) $this->pendingLines($company)
            ->whereIn('match_status', [
                StatementLineMatchStatus::Unmatched->value,
                StatementLineMatchStatus::Suggested->value,
            ])
            ->min('txn_date'));

        $oldestDisplay = $this->formatDay($oldest);

        $body = $suggested > 0
            ? __(':suggested already have suggested matches ready to confirm. A few minutes on the reconcile screen keeps your books current.', ['suggested' => $suggested])
            : __('The oldest dates back to :oldest. A few minutes on the reconcile screen keeps your books current.', ['oldest' => $oldestDisplay]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: 60 + min(20, intdiv($total, 10)),
            facts: [
                'unmatched_count' => $unmatched,
                'suggested_count' => $suggested,
                'total_count' => $total,
                'oldest_txn_date' => $oldest->toDateString(),
                'oldest_txn_date_display' => $oldestDisplay,
            ],
            headline: __(':count bank lines are waiting to be matched', ['count' => $total]),
            body: $body,
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'banking.reconcile', 'label' => __('Open reconcile')];
    }

    /**
     * @return Builder<BankStatementLine>
     */
    private function pendingLines(Company $company): Builder
    {
        return BankStatementLine::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id);
    }
}
