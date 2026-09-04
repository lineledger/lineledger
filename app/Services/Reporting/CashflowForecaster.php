<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Scopes\CompanyScope;
use App\Support\Reporting\SourceLinkResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Forward cash projection — the flagship advisory engine. Deterministic: a pure
 * function of the GL, open A/R, and open A/P, so the same books always produce
 * the same forecast.
 *
 * Two tracks per period:
 *   • **Committed** (high confidence) — opening cash, plus:
 *       – each open invoice's balance landing when it is realistically expected
 *         (its due date shifted by how late customers typically pay; anything
 *         already overdue is counted now, but a receivable overdue by more than
 *         `doubtfulAfterDays` is left out and listed separately as doubtful),
 *       – minus each open bill's balance on its due date (overdue paid now),
 *       – plus/minus every *post-dated* posted cash movement — a post-dated
 *         cheque, a future-dated service charge, a scheduled transfer — on the
 *         date it is booked. These sit in the GL but not in "cash today".
 *     This drives the below-floor alarm.
 *   • **Run-rate estimate** (flagged) — a single trailing-90-day net operating
 *     cash run-rate applied per period for steady-state operations not yet on
 *     the books as an open document. Because that run-rate already embodies
 *     recurring bills/invoices, recurring templates are deliberately NOT
 *     projected on top (that would double-count); a future itemised model could
 *     net them out of the run-rate instead.
 *
 * Opening cash is the book balance of the cash accounts as of today, which
 * already has every written-but-uncleared cheque deducted; `cash_position`
 * breaks that down (cleared at bank, uncleared payments, deposits in transit)
 * so the reader can tie it to the bank statement.
 *
 * Every period carries the items behind its totals (`items_in` / `items_out`)
 * so the report can show the rationale for each number.
 *
 * Opening cash and the run-rate use only `<= date` reads, and due-date
 * bucketing happens in PHP, so the projection is identical on MySQL and SQLite.
 */
final class CashflowForecaster
{
    private const DEFAULT_WEEKS = 13;

    private const DEFAULT_MONTHS = 6;

    private const RUNRATE_LOOKBACK_DAYS = 90;

    /** Receivables overdue by more than this many days are not counted as committed cash. */
    public const DEFAULT_DOUBTFUL_AFTER_DAYS = 90;

    /** Paid-invoice history used to learn how late customers typically pay. */
    private const COLLECTION_HISTORY_DAYS = 365;

    private const COLLECTION_DELAY_MIN_SAMPLES = 3;

    private const COLLECTION_DELAY_CAP_DAYS = 90;

    /** Rationale rows kept per period; the remainder is folded into one "more" row. */
    private const MAX_ITEMS_PER_PERIOD = 12;

    public function __construct(
        private readonly FinancialMetrics $metrics,
        private readonly ReportCalculator $calculator,
        private readonly SourceLinkResolver $sources,
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
        ?int $doubtfulAfterDays = null,
    ): array {
        $granularity = $granularity === 'month' ? 'month' : 'week';
        $count = max(1, min($periods ?? ($granularity === 'month' ? self::DEFAULT_MONTHS : self::DEFAULT_WEEKS), 53));
        $doubtfulAfterDays = max(0, $doubtfulAfterDays ?? self::DEFAULT_DOUBTFUL_AFTER_DAYS);

        $today = CarbonImmutable::parse(($asOf ?? $company->currentDateTime())->toDateString());
        $ranges = $this->buildPeriods($today, $granularity, $count);
        $horizonEnd = $ranges[count($ranges) - 1]['end'];

        $opening = $this->metrics->cashOnHand($company, $today);
        $collectionDelayDays = $this->collectionDelayDays($company, $today);

        ['items' => $invoiceItems, 'doubtful' => $doubtful] = $this->openInvoices($company, $today, $collectionDelayDays, $doubtfulAfterDays);
        $billItems = $this->openBills($company, $today);
        ['in' => $scheduledIn, 'out' => $scheduledOut] = $this->scheduledCashMovements($company, $today, $horizonEnd);

        $itemsIn = $this->bucketByDate([...$invoiceItems, ...$scheduledIn], $ranges, $today);
        $itemsOut = $this->bucketByDate([...$billItems, ...$scheduledOut], $ranges, $today);
        $runrateDaily = $this->runrateDailyCents($company, $today);

        $committedClosing = $opening;
        $projectedClosing = $opening;
        $lowestCommitted = $opening;
        $lowestIndex = -1; // -1 = opening is the low-water mark
        $firstBreachIndex = null;

        $periodsOut = [];

        foreach ($ranges as $i => $range) {
            $in = $this->sumAmounts($itemsIn[$i] ?? []);
            $out = $this->sumAmounts($itemsOut[$i] ?? []);
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
                'items_in' => $this->condense($itemsIn[$i] ?? []),
                'items_out' => $this->condense($itemsOut[$i] ?? []),
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
            'cash_position' => $this->cashPosition($company, $today, $opening),
            'runrate_daily_cents' => $runrateDaily,
            'collection_delay_days' => $collectionDelayDays,
            'doubtful_after_days' => $doubtfulAfterDays,
            'doubtful_receivables' => $doubtful,
            'doubtful_receivables_cents' => $this->sumAmounts($doubtful),
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
     * Place each dated item into the period covering its expected date. Items
     * whose date has already passed land in the first period (expected to be
     * collected/paid now); anything past the horizon is dropped.
     *
     * @param  list<array{date: CarbonImmutable, amount_cents: int, label: string, detail: string, kind: string}>  $items
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $ranges
     * @return array<int, list<array{date: string, amount_cents: int, label: string, detail: string, kind: string}>>
     */
    private function bucketByDate(array $items, array $ranges, CarbonImmutable $today): array
    {
        $horizonEnd = $ranges[count($ranges) - 1]['end'];
        $buckets = [];

        foreach ($items as $item) {
            $date = $item['date'];

            if ($date->lessThan($today)) {
                $index = 0;
            } elseif ($date->greaterThan($horizonEnd)) {
                continue;
            } else {
                $index = $this->periodIndexFor($date, $ranges);

                if ($index === null) {
                    continue;
                }
            }

            $buckets[$index][] = [...$item, 'date' => $date->toDateString()];
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
     * @param  list<array{amount_cents: int}>  $items
     */
    private function sumAmounts(array $items): int
    {
        return (int) array_sum(array_map(fn (array $item): int => (int) $item['amount_cents'], $items));
    }

    /**
     * Largest items first, capped so a busy period's tooltip stays readable;
     * the tail is folded into a single "… and N more" row.
     *
     * @param  list<array{date: string, amount_cents: int, label: string, detail: string, kind: string}>  $items
     * @return list<array{date: string, amount_cents: int, label: string, detail: string, kind: string}>
     */
    private function condense(array $items): array
    {
        usort($items, fn (array $a, array $b): int => $b['amount_cents'] <=> $a['amount_cents']);

        if (count($items) <= self::MAX_ITEMS_PER_PERIOD) {
            return $items;
        }

        $kept = array_slice($items, 0, self::MAX_ITEMS_PER_PERIOD - 1);
        $rest = array_slice($items, self::MAX_ITEMS_PER_PERIOD - 1);

        $kept[] = [
            'date' => $rest[0]['date'],
            'amount_cents' => $this->sumAmounts($rest),
            'label' => trans_choice('{1} :count more item|[2,*] :count more items', count($rest), ['count' => count($rest)]),
            'detail' => '',
            'kind' => 'more',
        ];

        return $kept;
    }

    /**
     * Open (posted/partial) invoices as expected collections. The expected date
     * is the due date pushed out by the company's typical days-late; overdue
     * balances are collected now unless they are older than the doubtful
     * cut-off, in which case they are reported separately and not counted.
     *
     * @return array{
     *   items: list<array{date: CarbonImmutable, amount_cents: int, label: string, detail: string, kind: string}>,
     *   doubtful: list<array{date: string, amount_cents: int, label: string, detail: string, kind: string}>
     * }
     */
    private function openInvoices(Company $company, CarbonImmutable $today, int $delayDays, int $doubtfulAfterDays): array
    {
        $items = [];
        $doubtful = [];

        $invoices = Invoice::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->with(['contact' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($invoices as $invoice) {
            $amount = $invoice->balanceCents();

            if ($amount <= 0) {
                continue;
            }

            $due = $this->dueDate($invoice->due_date, $invoice->invoice_date);
            $daysOverdue = $due->lessThan($today) ? (int) $due->diffInDays($today) : 0;
            $label = $this->documentLabel(__('Invoice :no', ['no' => $invoice->invoice_no]), $invoice->contact?->display_name);

            if ($daysOverdue > $doubtfulAfterDays) {
                $doubtful[] = [
                    'date' => $due->toDateString(),
                    'amount_cents' => $amount,
                    'label' => $label,
                    'detail' => __('due :date, :days days overdue — not counted', ['date' => $due->isoFormat('MMM D'), 'days' => $daysOverdue]),
                    'kind' => 'invoice',
                ];

                continue;
            }

            $expected = $due->addDays($delayDays);
            $parts = [__('due :date', ['date' => $due->isoFormat('MMM D')])];

            if ($daysOverdue > 0) {
                $parts[] = __(':days days overdue', ['days' => $daysOverdue]);
            }

            if ($expected->lessThan($today)) {
                $parts[] = __('counted now');
            } elseif ($delayDays > 0) {
                $parts[] = __('expected :date (customers typically pay :days days late)', ['date' => $expected->isoFormat('MMM D'), 'days' => $delayDays]);
            }

            $items[] = [
                'date' => $expected,
                'amount_cents' => $amount,
                'label' => $label,
                'detail' => implode(' · ', $parts),
                'kind' => 'invoice',
            ];
        }

        return ['items' => $items, 'doubtful' => $doubtful];
    }

    /**
     * Open (posted/partial) bills as due-dated outstanding balances.
     *
     * @return list<array{date: CarbonImmutable, amount_cents: int, label: string, detail: string, kind: string}>
     */
    private function openBills(Company $company, CarbonImmutable $today): array
    {
        $items = [];

        $bills = Bill::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->with(['contact' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($bills as $bill) {
            $amount = $bill->balanceCents();

            if ($amount <= 0) {
                continue;
            }

            $due = $this->dueDate($bill->due_date, $bill->bill_date);
            $daysOverdue = $due->lessThan($today) ? (int) $due->diffInDays($today) : 0;
            $no = $bill->bill_no ?: ($bill->vendor_reference ?: '#'.$bill->id);
            $parts = [__('due :date', ['date' => $due->isoFormat('MMM D')])];

            if ($daysOverdue > 0) {
                $parts[] = __(':days days overdue', ['days' => $daysOverdue]);
                $parts[] = __('counted now');
            }

            $items[] = [
                'date' => $due,
                'amount_cents' => $amount,
                'label' => $this->documentLabel(__('Bill :no', ['no' => $no]), $bill->contact?->display_name),
                'detail' => implode(' · ', $parts),
                'kind' => 'bill',
            ];
        }

        return $items;
    }

    /**
     * Posted GL movements on cash accounts dated after today and within the
     * horizon — post-dated cheques, future-dated service charges, scheduled
     * transfers. They are excluded from "cash today" (a `<= today` read) so
     * they are projected here on their booked date, netted per journal entry
     * (a transfer between two cash accounts nets to zero and is dropped).
     *
     * @return array{
     *   in: list<array{date: CarbonImmutable, amount_cents: int, label: string, detail: string, kind: string}>,
     *   out: list<array{date: CarbonImmutable, amount_cents: int, label: string, detail: string, kind: string}>
     * }
     */
    private function scheduledCashMovements(Company $company, CarbonImmutable $today, CarbonImmutable $horizonEnd): array
    {
        $cashAccountIds = $this->accountIdsForSubtypes($company, FinancialMetrics::CASH_SUBTYPES);

        if ($cashAccountIds === []) {
            return ['in' => [], 'out' => []];
        }

        $lines = JournalLine::query()
            ->whereIn('account_id', $cashAccountIds)
            ->where('is_posted', true)
            ->where('entry_date', '>', $today->toDateString())
            ->where('entry_date', '<=', $horizonEnd->toDateString())
            ->with(['journalEntry' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        $byEntry = [];

        foreach ($lines as $line) {
            $entry = $line->journalEntry;

            if ($entry === null) {
                continue;
            }

            $byEntry[$entry->id] ??= ['entry' => $entry, 'net' => 0];
            $byEntry[$entry->id]['net'] += (int) $line->debit_cents - (int) $line->credit_cents;
        }

        $in = [];
        $out = [];

        foreach ($byEntry as ['entry' => $entry, 'net' => $net]) {
            if ($net === 0) {
                continue;
            }

            $date = CarbonImmutable::parse($entry->entry_date)->startOfDay();

            $item = [
                'date' => $date,
                'amount_cents' => abs($net),
                'label' => (string) ($entry->memo ?: $entry->entry_no),
                'detail' => __(':source dated :date', [
                    'source' => $this->sources->label($entry),
                    'date' => $date->isoFormat('MMM D'),
                ]),
                'kind' => 'scheduled',
            ];

            if ($net > 0) {
                $in[] = $item;
            } else {
                $out[] = $item;
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /**
     * How today's book cash ties to the bank: for every bank account that is
     * actually reconciled (has at least one cleared line), the cleared balance,
     * the payments written but not yet cleared, and deposits not yet cleared,
     * all as of today. Cash in accounts that are never cleared (petty cash,
     * undeposited funds, an unreconciled bank) is reported as "other".
     *
     * @return array{
     *   tracked: bool,
     *   cleared_cents: int,
     *   outstanding_payments_cents: int,
     *   outstanding_payments_count: int,
     *   deposits_in_transit_cents: int,
     *   deposits_in_transit_count: int,
     *   other_cash_cents: int
     * }
     */
    private function cashPosition(Company $company, CarbonImmutable $today, int $bookCents): array
    {
        $bankIds = $this->accountIdsForSubtypes($company, [AccountSubtype::Bank]);

        $trackedIds = $bankIds === [] ? [] : JournalLine::query()
            ->whereIn('account_id', $bankIds)
            ->whereNotNull('cleared_at')
            ->distinct()
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($trackedIds === []) {
            return [
                'tracked' => false,
                'cleared_cents' => 0,
                'outstanding_payments_cents' => 0,
                'outstanding_payments_count' => 0,
                'deposits_in_transit_cents' => 0,
                'deposits_in_transit_count' => 0,
                'other_cash_cents' => $bookCents,
            ];
        }

        $base = fn () => JournalLine::query()
            ->whereIn('account_id', $trackedIds)
            ->where('is_posted', true)
            ->where('entry_date', '<=', $today->toDateString());

        $cleared = (int) $base()
            ->whereNotNull('cleared_at')
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS s')
            ->value('s');

        $payments = $base()
            ->whereNull('cleared_at')
            ->where('credit_cents', '>', 0)
            ->selectRaw('COALESCE(SUM(credit_cents), 0) AS s, COUNT(*) AS n')
            ->first();

        $deposits = $base()
            ->whereNull('cleared_at')
            ->where('debit_cents', '>', 0)
            ->selectRaw('COALESCE(SUM(debit_cents), 0) AS s, COUNT(*) AS n')
            ->first();

        $outstanding = (int) ($payments->s ?? 0);
        $inTransit = (int) ($deposits->s ?? 0);

        return [
            'tracked' => true,
            'cleared_cents' => $cleared,
            'outstanding_payments_cents' => $outstanding,
            'outstanding_payments_count' => (int) ($payments->n ?? 0),
            'deposits_in_transit_cents' => $inTransit,
            'deposits_in_transit_count' => (int) ($deposits->n ?? 0),
            'other_cash_cents' => $bookCents - ($cleared + $inTransit - $outstanding),
        ];
    }

    /**
     * How many days past the due date customers typically pay: the median lag
     * (floored at zero) across invoices paid in the trailing year, taken from
     * the last posted receipt applied to each. Needs a few samples to say
     * anything; capped so one slow year cannot push collections off the chart.
     */
    private function collectionDelayDays(Company $company, CarbonImmutable $today): int
    {
        $invoices = Invoice::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Paid->value)
            ->where('due_date', '>=', $today->subDays(self::COLLECTION_HISTORY_DAYS)->toDateString())
            ->with(['receiptApplications.receipt' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        $samples = [];

        foreach ($invoices as $invoice) {
            if ($invoice->due_date === null) {
                continue;
            }

            $paidOn = $invoice->receiptApplications
                ->map(fn ($application) => $application->receipt)
                ->filter(fn ($receipt): bool => $receipt !== null
                    && $receipt->status === ReceiptStatus::Posted
                    && $receipt->receipt_date !== null)
                ->map(fn ($receipt): string => CarbonImmutable::parse($receipt->receipt_date)->toDateString())
                ->max();

            if ($paidOn === null) {
                continue;
            }

            $due = CarbonImmutable::parse($invoice->due_date)->startOfDay();
            $samples[] = max(0, (int) $due->diffInDays(CarbonImmutable::parse($paidOn), false));
        }

        if (count($samples) < self::COLLECTION_DELAY_MIN_SAMPLES) {
            return 0;
        }

        sort($samples);
        $mid = intdiv(count($samples), 2);
        $median = count($samples) % 2 === 1
            ? $samples[$mid]
            : intdiv($samples[$mid - 1] + $samples[$mid], 2);

        return min(self::COLLECTION_DELAY_CAP_DAYS, $median);
    }

    /**
     * @param  list<AccountSubtype>  $subtypes
     * @return list<int>
     */
    private function accountIdsForSubtypes(Company $company, array $subtypes): array
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('subtype', array_map(fn (AccountSubtype $subtype): string => $subtype->value, $subtypes))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function documentLabel(string $document, ?string $party): string
    {
        return $party !== null && $party !== '' ? $document.' · '.$party : $document;
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
