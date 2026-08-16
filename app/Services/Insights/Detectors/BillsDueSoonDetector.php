<?php

namespace App\Services\Insights\Detectors;

use App\Enums\BillStatus;
use App\Enums\InsightCategory;
use App\Models\Bill;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * Heads-up when vendor bills fall due within the next seven days — the same
 * window the dashboard's accounts-payable card counts — so payments can be
 * lined up before late fees start.
 */
final class BillsDueSoonDetector implements InsightDetector
{
    use FormatsInsightFacts;

    public function key(): string
    {
        return 'bills-due-soon';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Deadline;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $windowStart = $today->toDateString();
        $windowEnd = $today->addDays(7)->toDateString();

        // Mirrors the dashboard accountsPayable() due-this-week query (open
        // vendor bills already on the books, unpaid, due inside the window);
        // the result set is small, so the exact balance and FX conversion
        // happen per row below.
        $bills = Bill::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->vendor()
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->where('bill_date', '<=', $windowStart)
            ->whereRaw('total_cents - amount_paid_cents > 0')
            ->whereBetween('due_date', [$windowStart, $windowEnd])
            ->get();

        $billCount = 0;
        $totalCents = 0;
        $soonestDue = null;
        $latestDue = null;

        foreach ($bills as $bill) {
            $balance = $bill->balanceCents();

            if ($balance <= 0) {
                continue; // settled outside the payment system (reconciled_cents)
            }

            // Foreign bills convert at their locked rate, exactly as the AP
            // aging report does, so the sum stays home-currency cents.
            // (getAttribute: currency_code/fx_rate carry no cast for Larastan.)
            $currency = $bill->getAttribute('currency_code');
            $rate = $bill->getAttribute('fx_rate');

            if ($currency !== null && ! $company->isHomeCurrency($currency) && $rate !== null) {
                $balance = Currency::toHomeCents($balance, (string) $rate);
            }

            $billCount++;
            $totalCents += $balance;

            $due = CarbonImmutable::parse($bill->due_date)->startOfDay();

            if ($soonestDue === null || $due->lessThan($soonestDue)) {
                $soonestDue = $due;
            }
            if ($latestDue === null || $due->greaterThan($latestDue)) {
                $latestDue = $due;
            }
        }

        if ($billCount < 1 || $soonestDue === null || $latestDue === null) {
            return [];
        }

        $score = 75;
        if ($billCount >= 3) {
            $score += 5;
        }
        if ($soonestDue->toDateString() <= $today->addDays(2)->toDateString()) {
            $score += 5;
        }

        $headline = trans_choice(
            '{1} 1 bill comes due this week (:total)|[2,*] :count bills come due this week (:total)',
            $billCount,
            ['count' => $billCount, 'total' => $this->formatWhole($totalCents)],
        );

        $body = $soonestDue->toDateString() === $latestDue->toDateString()
            ? __("They're due :date. Lining up payment now avoids late fees.", ['date' => $this->formatDay($soonestDue)])
            : __("They're due between :from and :to. Lining up payments now avoids late fees.", [
                'from' => $this->formatDay($soonestDue),
                'to' => $this->formatDay($latestDue),
            ]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: min($score, 85),
            facts: [
                'bill_count' => $billCount,
                'total_cents' => $totalCents,
                'total_display' => $this->formatWhole($totalCents),
                'soonest_due_date' => $soonestDue->toDateString(),
                'soonest_due_display' => $this->formatDay($soonestDue),
                'latest_due_date' => $latestDue->toDateString(),
                'latest_due_display' => $this->formatDay($latestDue),
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
        return ['route' => 'reports.ap-aging', 'label' => __('View AP aging')];
    }
}
