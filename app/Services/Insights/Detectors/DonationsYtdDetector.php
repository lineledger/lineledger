<?php

namespace App\Services\Insights\Detectors;

use App\Enums\DonationStatus;
use App\Enums\InsightCategory;
use App\Models\Company;
use App\Models\Donation;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fiscal-year-to-date giving for fundraising companies: total, gift count,
 * and distinct donors, compared against the same span last year when there
 * is one. Needs a little momentum (three posted gifts, one in the last two
 * weeks) so it reads as news, not a stale total. Aggregates only — never
 * donor names.
 */
final class DonationsYtdDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_GIFTS = 3;

    private const RECENT_DAYS = 14;

    public function __construct(private readonly ReportCalculator $reports) {}

    public function key(): string
    {
        return 'donations-ytd';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Fact;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        if (! $company->tracksFundraising()) {
            return [];
        }

        $fiscalYearStart = $this->reports->fiscalYearStart($company, $today);

        $giftCount = $this->postedBetween($company, $fiscalYearStart, $today)->count();

        if ($giftCount < self::MIN_GIFTS) {
            return [];
        }

        $hasRecentGift = $this->postedBetween($company, $fiscalYearStart, $today)
            ->where('donation_date', '>=', $today->subDays(self::RECENT_DAYS)->toDateString())
            ->exists();

        if (! $hasRecentGift) {
            return [];
        }

        $totalCents = (int) $this->postedBetween($company, $fiscalYearStart, $today)->sum('amount_cents');
        $donorCount = $this->postedBetween($company, $fiscalYearStart, $today)->distinct()->count('contact_id');

        $priorCents = (int) $this->postedBetween(
            $company,
            $fiscalYearStart->subYear(),
            $today->subMonthsNoOverflow(12),
        )->sum('amount_cents');

        $prior = $priorCents > 0 ? $priorCents : null;
        $pctChange = $prior !== null ? (int) round(($totalCents - $prior) / $prior * 100) : null;

        $totalDisplay = $this->formatWhole($totalCents);

        $comparison = $pctChange !== null && $pctChange >= 1
            ? __(', :pct% ahead of last year at this point', ['pct' => $pctChange])
            : '';

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $prior !== null && $totalCents * 10 >= $prior * 12 ? 55 : 45,
            facts: [
                'total_cents' => $totalCents,
                'total_display' => $totalDisplay,
                'gift_count' => $giftCount,
                'donor_count' => $donorCount,
                'prior_cents' => $prior,
                'prior_display' => $prior !== null ? $this->formatWhole($prior) : null,
                'pct_change' => $pctChange,
            ],
            headline: __('Donations have reached :total this year', ['total' => $totalDisplay]),
            body: __(':gifts gifts from :donors donors so far this fiscal year:comparison.', [
                'gifts' => $giftCount,
                'donors' => $donorCount,
                'comparison' => $comparison,
            ]),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.donations-by-donor', 'label' => __('View donations report')];
    }

    /**
     * @return Builder<Donation>
     */
    private function postedBetween(Company $company, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Donation::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('status', DonationStatus::Posted->value)
            ->whereBetween('donation_date', [$start->toDateString(), $end->toDateString()]);
    }
}
