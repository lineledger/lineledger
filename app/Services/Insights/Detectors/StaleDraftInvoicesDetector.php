<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * Spots invoices sitting in Draft for more than two weeks — money that never
 * starts the payment clock until the drafts are posted and sent.
 */
final class StaleDraftInvoicesDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const STALE_AFTER_DAYS = 14;

    public function key(): string
    {
        return 'stale-draft-invoices';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Hygiene;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $cutoff = $today->subDays(self::STALE_AFTER_DAYS)->toDateString();

        // Draft sets are small, so loading the rows for per-row FX conversion
        // and the oldest-date scan is cheap.
        $drafts = Invoice::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('status', InvoiceStatus::Draft->value)
            ->where('invoice_date', '<', $cutoff)
            ->get();

        $draftCount = $drafts->count();

        if ($draftCount < 2) {
            return [];
        }

        $totalCents = 0;
        $oldestDate = null;

        foreach ($drafts as $invoice) {
            $total = (int) $invoice->total_cents;

            // Foreign drafts convert at their captured rate, the same guard
            // the aging report applies (a draft without a rate stays as-is).
            // (getAttribute: currency_code/fx_rate carry no cast for Larastan.)
            $currency = $invoice->getAttribute('currency_code');
            $rate = $invoice->getAttribute('fx_rate');

            if ($currency !== null && ! $company->isHomeCurrency($currency) && $rate !== null) {
                $total = Currency::toHomeCents($total, (string) $rate);
            }

            $totalCents += $total;

            $date = CarbonImmutable::parse($invoice->invoice_date)->startOfDay();

            if ($oldestDate === null || $date->lessThan($oldestDate)) {
                $oldestDate = $date;
            }
        }

        if ($oldestDate === null) {
            return [];
        }

        $score = 55;
        if ($totalCents >= 100_000) {
            $score += 5;
        }

        $headline = __(":count draft invoices haven't been sent", ['count' => $draftCount]);

        $body = $company->tracksMembership()
            ? __('They total :total. Posting them gets dues notices out to your members.', [
                'total' => $this->formatWhole($totalCents),
            ])
            : __('They total :total and the oldest dates from :date. Posting them starts the clock on getting paid.', [
                'total' => $this->formatWhole($totalCents),
                'date' => $this->formatDay($oldestDate),
            ]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: min($score, 60),
            facts: [
                'draft_count' => $draftCount,
                'total_cents' => $totalCents,
                'total_display' => $this->formatWhole($totalCents),
                'oldest_invoice_date' => $oldestDate->toDateString(),
                'oldest_invoice_display' => $this->formatDay($oldestDate),
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
        return ['route' => 'invoices.index', 'label' => __('Open invoices')];
    }
}
