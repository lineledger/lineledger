<?php

namespace App\Services\Reporting;

use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Forward cash projection — the flagship advisory engine. Deterministic: a pure
 * function of the GL, open A/R, and open A/P, so the same books always produce
 * the same forecast.
 *
 * Two tracks per period:
 *   • **Committed** (high confidence) — opening cash, plus each open invoice's
 *     balance landing in the period of its due date (overdue collected now),
 *     minus each open bill's balance on its due date. This drives the
 *     below-floor alarm.
 *   • **Run-rate estimate** (flagged) — a single trailing-90-day net operating
 *     cash run-rate applied per period for steady-state operations not yet on
 *     the books as an open document. Because that run-rate already embodies
 *     recurring bills/invoices, recurring templates are deliberately NOT
 *     projected on top (that would double-count); a future itemised model could
 *     net them out of the run-rate instead.
 *
 * Opening cash and the run-rate use only `<= date` reads, and due-date
 * bucketing happens in PHP, so the projection is identical on MySQL and SQLite.
 */
final class CashflowForecaster
{
    private const DEFAULT_WEEKS = 13;

    private const DEFAULT_MONTHS = 6;

    private const RUNRATE_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly FinancialMetrics $metrics,
        private readonly ReportCalculator $calculator,
    ) {}

    /**
     * @param  'week'|'month'  $granularity
     * @return array<string, mixed>
     */
    public function forecast(
        Company $company,
        string $granularity = 'week',
        ?int $periods = null,
        int $floorCents = 0,
        ?CarbonInterface $asOf = null,
    ): array {
        $granularity = $granularity === 'month' ? 'month' : 'week';
        $count = max(1, min($periods ?? ($granularity === 'month' ? self::DEFAULT_MONTHS : self::DEFAULT_WEEKS), 53));

        $today = CarbonImmutable::parse(($asOf ?? $company->currentDateTime())->toDateString());
        $ranges = $this->buildPeriods($today, $granularity, $count);

        $opening = $this->metrics->cashOnHand($company, $today);
        $scheduledIn = $this->bucketByDueDate($this->openInvoices($company), $ranges, $today);
        $scheduledOut = $this->bucketByDueDate($this->openBills($company), $ranges, $today);
        $runrateDaily = $this->runrateDailyCents($company, $today);

        $committedClosing = $opening;
        $projectedClosing = $opening;
        $lowestCommitted = $opening;
        $lowestIndex = -1; // -1 = opening is the low-water mark
        $firstBreachIndex = null;

        $periodsOut = [];

        foreach ($ranges as $i => $range) {
            $in = $scheduledIn[$i] ?? 0;
            $out = $scheduledOut[$i] ?? 0;
            $committedNet = $in - $out;
            $committedClosing += $committedNet;

            $days = (int) $range['start']->diffInDays($range['end']) + 1;
            $runrateNet = $runrateDaily * $days;
            $projectedClosing += $committedNet + $runrateNet;

            $belowFloor = $committedClosing < $floorCents;
            if ($belowFloor && $firstBreachIndex === null) {
                $firstBreachIndex = $i;
            }
            if ($committedClosing < $lowestCommitted) {
                $lowestCommitted = $committedClosing;
                $lowestIndex = $i;
            }

            $periodsOut[] = [
                'index' => $i,
                'start' => $range['start']->toDateString(),
                'end' => $range['end']->toDateString(),
                'label' => $range['label'],
                'scheduled_in_cents' => $in,
                'scheduled_out_cents' => $out,
                'committed_net_cents' => $committedNet,
                'committed_closing_cents' => $committedClosing,
                'runrate_net_cents' => $runrateNet,
                'projected_closing_cents' => $projectedClosing,
                'below_floor' => $belowFloor,
            ];
        }

        return [
            'granularity' => $granularity,
            'start' => $today->toDateString(),
            'floor_cents' => $floorCents,
            'opening_cents' => $opening,
            'runrate_daily_cents' => $runrateDaily,
            'periods' => $periodsOut,
            'lowest_committed_cents' => $lowestCommitted,
            'lowest_committed_index' => $lowestIndex,
            'breaches_floor' => $firstBreachIndex !== null,
            'first_breach_index' => $firstBreachIndex,
            'first_breach_date' => $firstBreachIndex !== null ? $periodsOut[$firstBreachIndex]['end'] : null,
        ];
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    private function buildPeriods(CarbonImmutable $today, string $granularity, int $count): array
    {
        $ranges = [];

        if ($granularity === 'month') {
            for ($i = 0; $i < $count; $i++) {
                $start = $i === 0 ? $today : $today->addMonthsNoOverflow($i)->startOfMonth();
                $ranges[] = ['start' => $start, 'end' => $start->endOfMonth()->startOfDay(), 'label' => $start->format('M Y')];
            }

            return $ranges;
        }

        for ($i = 0; $i < $count; $i++) {
            $start = $today->addDays($i * 7);
            $ranges[] = [
                'start' => $start,
                'end' => $start->addDays(6),
                'label' => __('Wk of :date', ['date' => $start->format('M j')]),
            ];
        }

        return $ranges;
    }

    /**
     * Sum each due-dated amount into the period covering its due date. Overdue
     * items land in the first period (expected to be collected/paid now);
     * anything past the horizon is dropped.
     *
     * @param  list<array{due: CarbonImmutable, amount: int}>  $items
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $ranges
     * @return array<int, int>
     */
    private function bucketByDueDate(array $items, array $ranges, CarbonImmutable $today): array
    {
        $horizonEnd = $ranges[count($ranges) - 1]['end'];
        $buckets = [];

        foreach ($items as $item) {
            $due = $item['due'];

            if ($due->lessThan($today)) {
                $index = 0;
            } elseif ($due->greaterThan($horizonEnd)) {
                continue;
            } else {
                $index = $this->periodIndexFor($due, $ranges);

                if ($index === null) {
                    continue;
                }
            }

            $buckets[$index] = ($buckets[$index] ?? 0) + $item['amount'];
        }

        return $buckets;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $ranges
     */
    private function periodIndexFor(CarbonImmutable $date, array $ranges): ?int
    {
        foreach ($ranges as $i => $range) {
            if ($date->betweenIncluded($range['start'], $range['end'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Open (posted/partial) invoices as due-dated outstanding balances.
     *
     * @return list<array{due: CarbonImmutable, amount: int}>
     */
    private function openInvoices(Company $company): array
    {
        return Invoice::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'due' => $this->dueDate($invoice->due_date, $invoice->invoice_date),
                'amount' => $invoice->balanceCents(),
            ])
            ->filter(fn (array $row): bool => $row['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Open (posted/partial) bills as due-dated outstanding balances.
     *
     * @return list<array{due: CarbonImmutable, amount: int}>
     */
    private function openBills(Company $company): array
    {
        return Bill::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->get()
            ->map(fn (Bill $bill): array => [
                'due' => $this->dueDate($bill->due_date, $bill->bill_date),
                'amount' => $bill->balanceCents(),
            ])
            ->filter(fn (array $row): bool => $row['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Date-only due date, falling back to the document date, then to the distant
     * past (→ overdue). Accepts the date-cast value as either a Carbon instance
     * or its string form, so it's robust to how the cast is inferred.
     */
    private function dueDate(CarbonInterface|string|null $due, CarbonInterface|string|null $fallback): CarbonImmutable
    {
        $date = $due ?? $fallback;

        return $date !== null
            ? CarbonImmutable::parse($date)->startOfDay()
            : CarbonImmutable::parse('1970-01-01');
    }

    /**
     * Recent net operating cash per day, from the trailing-90-day indirect cash
     * flow. Positive = generating cash, negative = burning. Truncated toward
     * zero to integer cents.
     */
    private function runrateDailyCents(Company $company, CarbonImmutable $today): int
    {
        $cashFlow = $this->calculator->cashFlow(
            $company,
            $today->subDays(self::RUNRATE_LOOKBACK_DAYS),
            $today->subDay(),
        );

        return intdiv($cashFlow['total_operating'], self::RUNRATE_LOOKBACK_DAYS);
    }
}
