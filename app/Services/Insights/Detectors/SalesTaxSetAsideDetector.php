<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Enums\TaxReturnStatus;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * Sales tax collected since the last filed return isn't the company's money.
 * For each agency (bounded — usually one or two), nets collected against input
 * credits from the day after the last FILED return (or fiscal-year start when
 * none) and surfaces the largest positive balance once it tops $500, so the
 * owner sets it aside before filing time.
 */
final class SalesTaxSetAsideDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_NET_CENTS = 50_000;

    private const BOOST_ABOVE_CENTS = 500_000;

    public function __construct(private readonly ReportCalculator $reports) {}

    public function key(): string
    {
        return 'sales-tax-set-aside';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Hygiene;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $agencies = TaxAgency::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get();

        $best = null;

        foreach ($agencies as $agency) {
            $since = $this->periodStart($company, $agency, $today);

            if ($since->greaterThan($today)) {
                continue;
            }

            $net = $this->reports->salesTaxForAgency($agency, $since, $today->startOfDay())['net'];

            if ($net <= 0) {
                continue;
            }

            if ($best === null || $net > $best['net']) {
                $best = ['agency' => $agency, 'net' => $net, 'since' => $since];
            }
        }

        if ($best === null || $best['net'] < self::MIN_NET_CENTS) {
            return [];
        }

        $netDisplay = $this->formatWhole($best['net']);
        $sinceDisplay = $this->formatDay($best['since']);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $best['net'] > self::BOOST_ABOVE_CENTS ? 75 : 65,
            facts: [
                'net_cents' => $best['net'],
                'net_display' => $netDisplay,
                'agency_name' => $best['agency']->name,
                'since_date' => $best['since']->toDateString(),
                'since_date_display' => $sinceDisplay,
            ],
            headline: __("You're holding :net in sales tax", ['net' => $netDisplay]),
            body: __('Since :since, tax collected has exceeded input credits by :net. Setting it aside now makes filing painless.', [
                'since' => $sinceDisplay,
                'net' => $netDisplay,
            ]),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.sales-tax', 'label' => __('View sales tax report')];
    }

    /**
     * The day after the agency's last filed return, or fiscal-year start when
     * nothing has been filed yet.
     */
    private function periodStart(Company $company, TaxAgency $agency, CarbonImmutable $today): CarbonImmutable
    {
        $lastFiledEnd = TaxReturn::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('tax_agency_id', $agency->id)
            ->where('status', TaxReturnStatus::Filed->value)
            ->max('period_end');

        if ($lastFiledEnd !== null) {
            return CarbonImmutable::parse((string) $lastFiledEnd)->addDay();
        }

        return $this->reports->fiscalYearStart($company, $today);
    }
}
