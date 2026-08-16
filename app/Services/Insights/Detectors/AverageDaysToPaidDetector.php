<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\ReceiptApplication;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;

/**
 * How quickly invoices actually get paid: the average invoice-date →
 * final-receipt-date span across invoices fully settled in the trailing 90
 * days, with a quarter-over-quarter comparison when the prior window has
 * enough data to be meaningful.
 */
final class AverageDaysToPaidDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_SETTLED_INVOICES = 5;

    public function key(): string
    {
        return 'average-days-to-paid';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Fact;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $windowEnd = $today->toDateString();
        $currentFloor = $today->subDays(90)->toDateString();
        $priorFloor = $today->subDays(180)->toDateString();

        // Posted receipt applications against fully settled invoices over the
        // last 180 days (both windows in one bounded query). The settled test
        // mirrors Invoice::balanceCents() === 0 as portable SQL; the date
        // grouping and diffs happen in PHP so MySQL and SQLite agree.
        $rows = ReceiptApplication::query()
            ->withoutGlobalScopes()
            ->join('customer_receipts', 'customer_receipts.id', '=', 'receipt_applications.customer_receipt_id')
            ->join('invoices', 'invoices.id', '=', 'receipt_applications.invoice_id')
            ->where('customer_receipts.company_id', $company->id)
            ->where('customer_receipts.status', ReceiptStatus::Posted->value)
            ->whereNull('customer_receipts.deleted_at')
            ->where('invoices.company_id', $company->id)
            ->whereNull('invoices.deleted_at')
            ->whereRaw('invoices.total_cents - invoices.amount_paid_cents - invoices.reconciled_cents = 0')
            ->where('customer_receipts.receipt_date', '>', $priorFloor)
            ->where('customer_receipts.receipt_date', '<=', $windowEnd)
            ->toBase()
            ->get([
                'receipt_applications.invoice_id as settled_invoice_id',
                'invoices.invoice_date as settled_invoice_date',
                'customer_receipts.receipt_date as settled_receipt_date',
            ]);

        // Final payment per invoice = the latest posted receipt against it.
        // Earlier partial payments outside the fetch window can't change the
        // MAX, so the per-invoice grouping below is exact for both windows.
        $byInvoice = [];

        foreach ($rows as $row) {
            $invoiceId = (int) $row->settled_invoice_id;
            $receiptDate = CarbonImmutable::parse((string) $row->settled_receipt_date)->toDateString();

            $byInvoice[$invoiceId] ??= [
                'invoice_date' => CarbonImmutable::parse((string) $row->settled_invoice_date)->toDateString(),
                'last_receipt' => $receiptDate,
            ];

            if ($receiptDate > $byInvoice[$invoiceId]['last_receipt']) {
                $byInvoice[$invoiceId]['last_receipt'] = $receiptDate;
            }
        }

        $currentDays = [];
        $priorDays = [];

        foreach ($byInvoice as $invoice) {
            // Prepayments can settle before the invoice date; clamp to zero
            // rather than letting negatives drag the average below reality.
            $days = max(0, (int) round(
                CarbonImmutable::parse($invoice['invoice_date'])->startOfDay()
                    ->diffInDays(CarbonImmutable::parse($invoice['last_receipt'])->startOfDay())
            ));

            if ($invoice['last_receipt'] > $currentFloor) {
                $currentDays[] = $days;
            } elseif ($invoice['last_receipt'] > $priorFloor) {
                $priorDays[] = $days;
            }
        }

        $invoiceCount = count($currentDays);

        if ($invoiceCount < self::MIN_SETTLED_INVOICES) {
            return [];
        }

        $avgDays = (int) round(array_sum($currentDays) / $invoiceCount);

        $priorAvgDays = count($priorDays) >= self::MIN_SETTLED_INVOICES
            ? (int) round(array_sum($priorDays) / count($priorDays))
            : null;

        $deltaDays = $priorAvgDays === null ? null : $avgDays - $priorAvgDays;

        $score = 40;
        if ($deltaDays !== null && $deltaDays <= -5) {
            $score += 10;
        }

        $comparison = '';
        if ($deltaDays !== null && $deltaDays <= -5) {
            $comparison = __(' — :days days faster than the quarter before', ['days' => abs($deltaDays)]);
        } elseif ($deltaDays !== null && abs($deltaDays) <= 2) {
            $comparison = __(' — about the same as the quarter before');
        }

        $body = $company->tracksMembership()
            ? __('Across :count dues invoices settled in the last 90 days:comparison.', [
                'count' => $invoiceCount,
                'comparison' => $comparison,
            ])
            : __('Across :count invoices settled in the last 90 days:comparison.', [
                'count' => $invoiceCount,
                'comparison' => $comparison,
            ]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: min($score, 50),
            facts: [
                'avg_days' => $avgDays,
                'invoice_count' => $invoiceCount,
                'prior_avg_days' => $priorAvgDays,
                'delta_days' => $deltaDays,
            ],
            headline: __('You get paid in :days days on average', ['days' => $avgDays]),
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
