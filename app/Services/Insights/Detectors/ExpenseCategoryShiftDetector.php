<?php

namespace App\Services\Insights\Detectors;

use App\Enums\AccountType;
use App\Enums\InsightCategory;
use App\Enums\NormalBalance;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;

/**
 * One expense category jumped sharply: last full month versus the month
 * before, per expense account. A single grouped pass over posted journal
 * lines with portable conditional SUMs (CASE WHEN entry_date BETWEEN …)
 * bound as Y-m-d date strings, so MySQL and SQLite bucket the months
 * identically — no MONTH()/strftime.
 */
final class ExpenseCategoryShiftDetector implements InsightDetector
{
    use FormatsInsightFacts;

    /** Last-month spend below $200 is too small to call a "category". */
    private const MIN_SPEND_CENTS = 20_000;

    /** Increases under $100 absolute are noise even when the % is big. */
    private const MIN_INCREASE_CENTS = 10_000;

    public function key(): string
    {
        return 'expense-category-shift';
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
        // Cheapest gate first: only look back in the first week of the month.
        if ($today->day > 7) {
            return [];
        }

        $lastStart = $today->startOfMonth()->subMonthNoOverflow();
        $lastEnd = $lastStart->endOfMonth();
        $priorStart = $lastStart->subMonthNoOverflow();
        $priorEnd = $priorStart->endOfMonth();

        // Per-account spend for each month in one grouped query. Sign follows
        // ReportCalculator::sumNaturalForType(): expenses are debit-normal, so
        // debit − credit is positive spend; credit-normal rows are flipped.
        // Detectors run without the current_company binding — scope explicitly.
        $rows = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $company->id)
            ->where('accounts.type', AccountType::Expense->value)
            ->where('journal_lines.is_posted', true)
            ->whereBetween('journal_lines.entry_date', [$priorStart->toDateString(), $lastEnd->toDateString()])
            ->groupBy('journal_lines.account_id', 'accounts.name', 'accounts.normal_balance')
            ->selectRaw(
                'journal_lines.account_id AS account_id, accounts.name AS account_name, accounts.normal_balance AS normal_balance, '
                .'COALESCE(SUM(CASE WHEN journal_lines.entry_date BETWEEN ? AND ? THEN journal_lines.debit_cents - journal_lines.credit_cents ELSE 0 END), 0) AS last_signed, '
                .'COALESCE(SUM(CASE WHEN journal_lines.entry_date BETWEEN ? AND ? THEN journal_lines.debit_cents - journal_lines.credit_cents ELSE 0 END), 0) AS prior_signed',
                [
                    $lastStart->toDateString(), $lastEnd->toDateString(),
                    $priorStart->toDateString(), $priorEnd->toDateString(),
                ],
            )
            ->toBase()
            ->get();

        $winner = null;

        foreach ($rows as $row) {
            $sign = $row->normal_balance === NormalBalance::Debit->value ? 1 : -1;
            $lastSpend = $sign * (int) $row->last_signed;
            $priorSpend = $sign * (int) $row->prior_signed;
            $increase = $lastSpend - $priorSpend;

            // A jump needs a real base month: with prior spend ≤ 0 there is no
            // percentage to state (a brand-new category is not a "shift").
            if ($lastSpend < self::MIN_SPEND_CENTS
                || $priorSpend <= 0
                || $increase < self::MIN_INCREASE_CENTS
                || $increase * 100 < $priorSpend * 30) {
                continue;
            }

            $accountId = (int) $row->account_id;

            if ($winner === null
                || $increase > $winner['increase']
                || ($increase === $winner['increase'] && $accountId < $winner['account_id'])) {
                $winner = [
                    'account_id' => $accountId,
                    'label' => (string) $row->account_name,
                    'last' => $lastSpend,
                    'prior' => $priorSpend,
                    'increase' => $increase,
                ];
            }
        }

        if ($winner === null) {
            return [];
        }

        $pct = (int) round($winner['increase'] * 100 / $winner['prior']);
        $score = min(40 + ($winner['increase'] >= $winner['prior'] ? 10 : 0), 50);

        // PRIVACY EXCEPTION (reviewed): account_label is the chart-of-accounts
        // account NAME — organization-defined category vocabulary ("Office
        // Supplies"), not personal data. Contact names and transaction
        // descriptions/memos remain banned from facts.
        $label = $winner['label'];
        $monthLabel = $lastStart->format('F');
        $priorMonthLabel = $priorStart->format('F');
        $amountDisplay = $this->formatWhole($winner['last']);
        $priorDisplay = $this->formatWhole($winner['prior']);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $score,
            facts: [
                'account_label' => $label,
                'month_label' => $monthLabel,
                'prior_month_label' => $priorMonthLabel,
                'amount_cents' => $winner['last'],
                'amount_display' => $amountDisplay,
                'prior_cents' => $winner['prior'],
                'prior_display' => $priorDisplay,
                'pct_increase' => $pct,
            ],
            headline: strlen($label) > 24
                ? __('One expense category jumped :pct% last month', ['pct' => $pct])
                : __(':account spending jumped :pct% last month', ['account' => $label, 'pct' => $pct]),
            body: __('You recorded :amount of :account in :month, up from :prior_amount in :prior_month.', [
                'amount' => $amountDisplay,
                'account' => $label,
                'month' => $monthLabel,
                'prior_amount' => $priorDisplay,
                'prior_month' => $priorMonthLabel,
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
