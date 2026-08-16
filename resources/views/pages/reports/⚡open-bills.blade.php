<?php

use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Services\Posting\BillReconciler;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Open Bills')] class extends Component
{
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportAsOfDate;

    private const SORT_FIELDS = ['bill_no', 'name', 'bill_date', 'due_date', 'total', 'paid', 'balance'];

    public Company $company;

    #[Url(as: 'sort')]
    public string $sortField = 'bill_date';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
    }

    /** @return array<string, string> */
    public function columnRegistry(): array
    {
        return [
            'due_date' => __('Due'),
            'total' => __('Total'),
            'paid' => __('Paid'),
        ];
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    /**
     * Every posted/partial vendor bill dated on or before the as-of date that still has a
     * balance owing — the documents behind the company's outstanding AP.
     *
     * Credit rows net each vendor down to their true GL AP balance (the figure AP Aging
     * reports), surfacing vendor credits AND unapplied bill payments as a single negative
     * row so the report's net ties to AP Aging.
     *
     * @return array{rows: array<int, array{id: int, type: string, bill_no: string, contact_id: ?int, name: string, bill_date: string, due_date: ?string, days_overdue: int, total: int, paid: int, balance: int}>, totals: array{total: int, paid: int, balance: int, count: int, gross_balance: int, credits: int}}
     */
    #[Computed]
    public function report(): array
    {
        $asOf = CarbonImmutable::parse($this->asOf);

        $bills = Bill::query()
            ->vendor()
            ->with('contact')
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->where('bill_date', '<=', $asOf)
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0')
            ->get();

        $rows = [];
        $totals = ['total' => 0, 'paid' => 0, 'balance' => 0, 'count' => 0, 'gross_balance' => 0, 'credits' => 0];

        foreach ($bills as $bill) {
            $balance = $bill->balanceCents();

            $dueDate = $bill->due_date ? CarbonImmutable::parse($bill->due_date) : null;
            $daysOverdue = $dueDate ? (int) $dueDate->diffInDays($asOf, false) : 0;

            $rows[] = [
                'id' => $bill->id,
                'type' => 'bill',
                'bill_no' => (string) $bill->bill_no,
                'contact_id' => $bill->contact_id,
                'name' => $bill->contact?->display_name ?? __('(no vendor)'),
                'bill_date' => CarbonImmutable::parse($bill->bill_date)->toDateString(),
                'due_date' => $dueDate?->toDateString(),
                'days_overdue' => max(0, $daysOverdue),
                'total' => (int) $bill->total_cents,
                'paid' => (int) $bill->amount_paid_cents,
                'balance' => $balance,
            ];

            $totals['total'] += (int) $bill->total_cents;
            $totals['paid'] += (int) $bill->amount_paid_cents;
            $totals['balance'] += $balance;
            $totals['gross_balance'] += $balance;
            $totals['count']++;
        }

        // On-account vendor credits (vendor credits + unapplied bill payments) that reduce
        // the vendor's GL AP below their open-bill docs. AP is credit-normal: GL = credit − debit.
        $contactIds = array_values(array_unique(array_filter(array_column($rows, 'contact_id'))));

        if ($contactIds !== []) {
            $apAccountIds = Account::query()
                ->where('company_id', $this->company->id)
                ->where('subtype', AccountSubtype::AccountsPayable->value)
                ->pluck('id');

            $glByContact = $apAccountIds->isEmpty() ? collect() : DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.company_id', $this->company->id)
                ->where('je.is_posted', true)
                ->whereIn('jl.account_id', $apAccountIds)
                ->whereIn('jl.contact_id', $contactIds)
                ->where('je.entry_date', '<=', $asOf)
                ->groupBy('jl.contact_id')
                ->selectRaw('jl.contact_id AS cid, SUM(jl.credit_cents - jl.debit_cents) AS bal')
                ->pluck('bal', 'cid');

            $openByContact = [];
            $nameByContact = [];

            foreach ($rows as $r) {
                $openByContact[$r['contact_id']] = ($openByContact[$r['contact_id']] ?? 0) + $r['balance'];
                $nameByContact[$r['contact_id']] = $r['name'];
            }

            foreach ($openByContact as $contactId => $open) {
                $gl = (int) ($glByContact[$contactId] ?? 0);
                $credit = max(0, $open - max(0, $gl));

                if ($credit <= 0) {
                    continue;
                }

                $rows[] = [
                    'id' => $contactId,
                    'type' => 'credit',
                    'bill_no' => '',
                    'contact_id' => $contactId,
                    'name' => $nameByContact[$contactId] ?? __('(no vendor)'),
                    'bill_date' => '',
                    'due_date' => null,
                    'days_overdue' => 0,
                    'total' => -$credit,
                    'paid' => 0,
                    'balance' => -$credit,
                ];

                $totals['total'] -= $credit;
                $totals['balance'] -= $credit;
                $totals['credits'] += $credit;
            }
        }

        $field = $this->sortField;
        $dirMul = $this->sortDir === 'desc' ? -1 : 1;

        usort($rows, function (array $a, array $b) use ($field, $dirMul) {
            $cmp = match ($field) {
                'name' => strcasecmp($a['name'], $b['name']),
                'bill_no' => strnatcasecmp($a['bill_no'], $b['bill_no']),
                'bill_date', 'due_date' => strcmp((string) $a[$field], (string) $b[$field]),
                default => $a[$field] <=> $b[$field],
            };

            return $cmp === 0
                ? strcmp($a['bill_date'], $b['bill_date'])
                : $cmp * $dirMul;
        });

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Close every open bill whose balance the ledger has already settled via a journal
     * entry (no GL is posted). Safe to run repeatedly — it never closes more than the
     * vendor's ledger supports.
     */
    public function reconcileSettled(BillReconciler $reconciler): void
    {
        $result = $reconciler->reconcileCompany($this->company);

        unset($this->report);

        if ($result['bills'] === 0) {
            Flux::toast(variant: 'warning', text: __('No bills to close — every open balance is still owed in the ledger.'));

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice(
            'Closed :count bill (:amount) already settled in the ledger.|Closed :count bills (:amount) already settled in the ledger.',
            $result['bills'],
            ['count' => $result['bills'], 'amount' => '$'.number_format($result['cents'] / 100, 2)],
        ));
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->openBills(
            "open-bills-{$this->asOf}.xlsx",
            $this->company,
            $this->report,
            $this->asOf,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.open-bills', [
            'company' => $this->company,
            'report' => $this->report,
            'asOf' => $this->asOf,
            'title' => $this->effectiveTitle('Open Bills'),
        ], "open-bills-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Open Bills'))"
        :subtitle="__('Posted bills with a balance still owing.')"
        mode="single"
        :exports="['xlsx', 'pdf']"
        :exports-disabled="empty($this->report['rows'])"
        :title-editable="true"
    >
        <flux:button
            variant="ghost"
            icon="check-circle"
            wire:click="reconcileSettled"
            wire:confirm="{{ __('Close every open bill whose balance was already settled by a journal entry? No new ledger entries are posted.') }}"
            data-test="reconcile-settled"
        >{{ __('Close ledger-settled') }}</flux:button>
        <x-reports.column-picker :columns="$this->columnRegistry()" />
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left"><x-sort-header field="bill_no" :current-field="$sortField" :current-dir="$sortDir" :label="__('Bill')" /></th>
                    <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Vendor')" /></th>
                    <th class="px-4 py-2 text-left"><x-sort-header field="bill_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                    @if ($this->columnVisible('due_date'))
                        <th class="px-4 py-2 text-left"><x-sort-header field="due_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Due')" /></th>
                    @endif
                    @if ($this->columnVisible('total'))
                        <th class="px-4 py-2 text-right"><x-sort-header field="total" :current-field="$sortField" :current-dir="$sortDir" :label="__('Total')" align="right" /></th>
                    @endif
                    @if ($this->columnVisible('paid'))
                        <th class="px-4 py-2 text-right"><x-sort-header field="paid" :current-field="$sortField" :current-dir="$sortDir" :label="__('Paid')" align="right" /></th>
                    @endif
                    <th class="px-4 py-2 text-right"><x-sort-header field="balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Balance')" align="right" /></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->report['rows'] as $row)
                    @if ($row['type'] === 'credit')
                        <tr data-test="open-bill-credit-row" class="bg-emerald-50/50 dark:bg-emerald-950/20">
                            <td class="px-4 py-2">
                                <flux:badge color="emerald" size="sm">{{ __('Credit') }}</flux:badge>
                            </td>
                            <td class="px-4 py-2">
                                @if ($row['contact_id'])
                                    <a
                                        href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['contact_id'], 'kind' => 'ap']) }}"
                                        class="underline"
                                    >{{ $row['name'] }}</a>
                                @else
                                    <span class="text-muted-foreground">{{ $row['name'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2"></td>
                            @if ($this->columnVisible('due_date'))
                                <td class="px-4 py-2 text-muted-foreground">{{ __('Vendor credits & unapplied payments') }}</td>
                            @endif
                            @if ($this->columnVisible('total'))
                                <td class="px-4 py-2 text-right font-mono text-emerald-700 dark:text-emerald-400">({{ number_format(abs($row['total']) / 100, 2) }})</td>
                            @endif
                            @if ($this->columnVisible('paid'))
                                <td class="px-4 py-2 text-right font-mono">—</td>
                            @endif
                            <td class="px-4 py-2 text-right font-mono font-semibold text-emerald-700 dark:text-emerald-400">({{ number_format(abs($row['balance']) / 100, 2) }})</td>
                        </tr>
                    @else
                        <tr data-test="open-bill-row">
                            <td class="px-4 py-2">
                                <a
                                    href="{{ route('bills.show', ['company' => $company->slug, 'bill' => $row['id']]) }}"
                                    wire:navigate
                                    class="font-mono underline"
                                >{{ $row['bill_no'] }}</a>
                            </td>
                            <td class="px-4 py-2">
                                @if ($row['contact_id'])
                                    <a
                                        href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['contact_id'], 'kind' => 'ap']) }}"
                                        class="underline"
                                    >{{ $row['name'] }}</a>
                                @else
                                    <span class="text-muted-foreground">{{ $row['name'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $row['bill_date'] }}</td>
                            @if ($this->columnVisible('due_date'))
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ $row['due_date'] ?? '—' }}
                                    @if ($row['days_overdue'] > 0)
                                        <span class="ms-1 text-xs text-red-600 dark:text-red-500">{{ __(':n d', ['n' => $row['days_overdue']]) }}</span>
                                    @endif
                                </td>
                            @endif
                            @if ($this->columnVisible('total'))
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row['total'] / 100, 2) }}</td>
                            @endif
                            @if ($this->columnVisible('paid'))
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row['paid'] / 100, 2) }}</td>
                            @endif
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($row['balance'] / 100, 2) }}</td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="{{ $this->visibleColumnCount(fixed: 4) }}" class="px-4 py-8 text-center text-muted-foreground">{{ __('No open bills as of this date.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->report['rows']))
                <tfoot class="bg-muted">
                    @if ($this->report['totals']['credits'] > 0)
                        <tr class="text-muted-foreground">
                            <td colspan="{{ $this->visibleColumnCount(fixed: 4) - 1 }}" class="px-4 py-2 text-right">{{ __('Open bills') }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="open-bills-gross">{{ number_format($this->report['totals']['gross_balance'] / 100, 2) }}</td>
                        </tr>
                        <tr class="text-emerald-700 dark:text-emerald-400">
                            <td colspan="{{ $this->visibleColumnCount(fixed: 4) - 1 }}" class="px-4 py-2 text-right">{{ __('Less vendor credits') }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="open-bills-credits">({{ number_format($this->report['totals']['credits'] / 100, 2) }})</td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="{{ 3 + ($this->columnVisible('due_date') ? 1 : 0) }}" class="px-4 py-2 text-right font-medium">{{ trans_choice(':count open bill|:count open bills', $this->report['totals']['count'], ['count' => $this->report['totals']['count']]) }}</td>
                        @if ($this->columnVisible('total'))
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report['totals']['total'] / 100, 2) }}</td>
                        @endif
                        @if ($this->columnVisible('paid'))
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report['totals']['paid'] / 100, 2) }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="open-bills-net">{{ number_format($this->report['totals']['balance'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
