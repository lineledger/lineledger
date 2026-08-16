<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;

/**
 * Concentration risk in receivables: when a single contact carries 40% or
 * more of everything owed, one slow payer becomes a cash-flow problem. Reads
 * only the denormalized contacts.ar_balance_cents aggregates — deliberately
 * no contact id or name, the AR Aging report is where the "who" lives.
 */
final class ReceivablesConcentrationDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_TOTAL_CENTS = 100_000;

    private const MIN_DEBTORS = 3;

    private const MIN_SHARE_PCT = 40;

    public function key(): string
    {
        return 'receivables-concentration';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Fact;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $row = Contact::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('ar_balance_cents', '>', 0)
            ->selectRaw('COUNT(*) as debtor_count, COALESCE(SUM(ar_balance_cents), 0) as total_owed_cents, COALESCE(MAX(ar_balance_cents), 0) as top_owed_cents')
            ->toBase()
            ->first();

        if ($row === null) {
            return [];
        }

        $debtorCount = (int) $row->debtor_count;
        $totalCents = (int) $row->total_owed_cents;
        $topCents = (int) $row->top_owed_cents;

        if ($totalCents < self::MIN_TOTAL_CENTS || $debtorCount < self::MIN_DEBTORS) {
            return [];
        }

        // Whole-percent share, rounded half-up in pure integer math.
        $topSharePct = intdiv($topCents * 200 + $totalCents, $totalCents * 2);

        if ($topSharePct < self::MIN_SHARE_PCT) {
            return [];
        }

        $score = 45;
        if ($topSharePct >= 60) {
            $score += 10;
        }

        $member = $company->tracksMembership();

        $headline = $member
            ? __('One member holds :share% of your receivables', ['share' => $topSharePct])
            : __('One customer holds :share% of your receivables', ['share' => $topSharePct]);

        $body = $member
            ? __('Of :total outstanding, :top is owed by a single member. The AR Aging report shows who.', [
                'total' => $this->formatWhole($totalCents),
                'top' => $this->formatWhole($topCents),
            ])
            : __('Of :total outstanding, :top is owed by a single customer. The AR Aging report shows who.', [
                'total' => $this->formatWhole($totalCents),
                'top' => $this->formatWhole($topCents),
            ]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: min($score, 55),
            facts: [
                'top_share_pct' => $topSharePct,
                'top_cents' => $topCents,
                'top_display' => $this->formatWhole($topCents),
                'total_cents' => $totalCents,
                'total_display' => $this->formatWhole($totalCents),
                'debtor_count' => $debtorCount,
            ],
            headline: $headline,
            body: $body,
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.ar-aging', 'label' => __('View AR aging')];
    }
}
