<?php

use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TaxAgency;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales Tax')] class extends Component {
    public Company $company;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    public ?int $drillAgencyId = null;

    public ?string $drillBucket = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if ($this->startDate === '') {
            $this->startDate = $this->company->currentDateTime()->startOfQuarter()->toDateString();
        }
        if ($this->endDate === '') {
            $this->endDate = $this->company->currentDateTime()->toDateString();
        }
    }

    /**
     * @return array<int, array{agency_id: int, agency: string, payable_account: string, collected: int, paid: int, net: int}>
     */
    #[Computed]
    public function rows(): array
    {
        $calc = app(ReportCalculator::class);
        $start = CarbonImmutable::parse($this->startDate);
        $end = CarbonImmutable::parse($this->endDate);

        return TaxAgency::query()
            ->with('payableAccount')
            ->orderBy('name')
            ->get()
            ->map(function (TaxAgency $a) use ($calc, $start, $end) {
                $row = $calc->salesTaxForAgency($a, $start, $end);

                return [
                    'agency_id' => (int) $a->id,
                    'agency' => $a->name,
                    'payable_account' => optional($a->payableAccount)->code.' — '.optional($a->payableAccount)->name,
                    'collected' => $row['collected'],
                    'paid' => $row['paid'],
                    'net' => $row['net'],
                ];
            })
            ->all();
    }

    public function totals(): array
    {
        $totals = ['collected' => 0, 'paid' => 0, 'net' => 0];

        foreach ($this->rows as $r) {
            $totals['collected'] += $r['collected'];
            $totals['paid'] += $r['paid'];
            $totals['net'] += $r['net'];
        }

        return $totals;
    }

    public function openDrill(int $agencyId, string $bucket): void
    {
        $this->drillAgencyId = $agencyId;
        $this->drillBucket = in_array($bucket, ['collected', 'paid'], true) ? $bucket : null;
    }

    /**
     * @return array{agency: ?TaxAgency, bucket: ?string, lines: \Illuminate\Support\Collection<int, array<string, mixed>>}
     */
    #[Computed]
    public function drill(): array
    {
        if ($this->drillAgencyId === null || $this->drillBucket === null) {
            return ['agency' => null, 'bucket' => null, 'lines' => collect()];
        }

        $agency = TaxAgency::query()->with('payableAccount')->find($this->drillAgencyId);
        if (! $agency) {
            return ['agency' => null, 'bucket' => null, 'lines' => collect()];
        }

        $calc = app(ReportCalculator::class);
        $lines = $calc->salesTaxLines(
            $agency,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        )->where('bucket', $this->drillBucket)->values();

        return ['agency' => $agency, 'bucket' => $this->drillBucket, 'lines' => $lines];
    }

    public function drillUrl(array $line): ?string
    {
        return match ($line['source_type']) {
            Invoice::class => $line['source_id']
                ? route('invoices.show', ['company' => $this->company->slug, 'invoice' => $line['source_id']])
                : null,
            Bill::class => $line['source_id']
                ? route('bills.show', ['company' => $this->company->slug, 'bill' => $line['source_id']])
                : null,
            Cheque::class => $line['source_id']
                ? route('cheques.show', ['company' => $this->company->slug, 'cheque' => $line['source_id']])
                : null,
            default => route('journal.show', ['company' => $this->company->slug, 'entry' => $line['entry_no']]),
        };
    }

    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['agency'], $r['payable_account'],
            CsvExporter::cents($r['collected']),
            CsvExporter::cents($r['paid']),
            CsvExporter::cents($r['net']),
        ]);

        $totals = $this->totals();
        $rows->push([
            'TOTAL', '',
            CsvExporter::cents($totals['collected']),
            CsvExporter::cents($totals['paid']),
            CsvExporter::cents($totals['net']),
        ]);

        return app(CsvExporter::class)->stream(
            "sales-tax-{$this->startDate}-{$this->endDate}.csv",
            ['Agency', 'Payable account', 'Collected on sales', 'Paid (ITC)', 'Net owing'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->salesTax(
            "sales-tax-{$this->startDate}-{$this->endDate}.xlsx",
            $this->company,
            $this->rows,
            $this->startDate,
            $this->endDate,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.sales-tax', [
            'company' => $this->company,
            'rows' => $this->rows,
            'totals' => $this->totals(),
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], "sales-tax-{$this->startDate}-{$this->endDate}.pdf");
    }

    public function exportDrillCsv()
    {
        $drill = $this->drill;
        if (! $drill['agency'] || ! $drill['bucket']) {
            return null;
        }

        $bucketSlug = $drill['bucket']; // collected | paid
        $rows = $drill['lines']->map(fn ($l) => [
            $l['entry_date']->toDateString(),
            $l['doc_label'],
            CsvExporter::cents($l['amount_cents']),
        ]);
        $rows->push(['', 'TOTAL', CsvExporter::cents($drill['lines']->sum('amount_cents'))]);

        return app(CsvExporter::class)->stream(
            "sales-tax-{$bucketSlug}-{$this->startDate}-{$this->endDate}.csv",
            ['Date', 'Document', 'Amount'],
            $rows,
        );
    }

    public function exportDrillXlsx()
    {
        $drill = $this->drill;
        if (! $drill['agency'] || ! $drill['bucket']) {
            return null;
        }

        $bucketSlug = $drill['bucket'];

        return app(XlsxExporter::class)->salesTaxDetail(
            "sales-tax-{$bucketSlug}-{$this->startDate}-{$this->endDate}.xlsx",
            $this->company,
            $drill['agency']->name,
            $drill['bucket'],
            $drill['lines'],
            $this->startDate,
            $this->endDate,
        );
    }

    public function exportDrillPdf()
    {
        $drill = $this->drill;
        if (! $drill['agency'] || ! $drill['bucket']) {
            return null;
        }

        $bucketSlug = $drill['bucket'];
        $bucketLabel = $bucketSlug === 'paid' ? 'Paid (ITC)' : 'Collected on sales';

        return app(PdfExporter::class)->download('pdf.reports.sales-tax-detail', [
            'company' => $this->company,
            'agencyName' => $drill['agency']->name,
            'bucketLabel' => $bucketLabel,
            'lines' => $drill['lines'],
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], "sales-tax-{$bucketSlug}-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sales Tax') }}</flux:heading>
            <flux:subheading>{{ __('Per-agency tax collected on sales vs. input tax credits claimed on purchases.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
            <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Agency') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Payable account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Collected on sales') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Paid (ITC)') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Net owing') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="tax-row">
                        <td class="px-4 py-2">{{ $row['agency'] }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $row['payable_account'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            <flux:modal.trigger name="sales-tax-drill">
                                <button type="button"
                                    wire:click="openDrill({{ $row['agency_id'] }}, 'collected')"
                                    class="underline decoration-dotted hover:text-blue-600"
                                    data-test="tax-collected">
                                    {{ number_format($row['collected'] / 100, 2) }}
                                </button>
                            </flux:modal.trigger>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            <flux:modal.trigger name="sales-tax-drill">
                                <button type="button"
                                    wire:click="openDrill({{ $row['agency_id'] }}, 'paid')"
                                    class="underline decoration-dotted hover:text-blue-600"
                                    data-test="tax-paid">
                                    {{ number_format($row['paid'] / 100, 2) }}
                                </button>
                            </flux:modal.trigger>
                        </td>
                        <td class="px-4 py-2 text-right font-mono font-semibold @if ($row['net'] < 0) text-green-600 @endif" data-test="tax-net">
                            {{ number_format($row['net'] / 100, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No tax agencies configured.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->rows))
                @php $totals = $this->totals(); @endphp
                <tfoot class="bg-muted">
                    <tr class="text-base">
                        <td colspan="2" class="px-4 py-2 text-right font-semibold">{{ __('Totals') }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="tax-total-collected">{{ number_format($totals['collected'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="tax-total-paid">{{ number_format($totals['paid'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold" data-test="tax-total-net">{{ number_format($totals['net'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Net owing is what you remit to the agency. Negative values mean the agency owes you a refund.') }}</p>
    </div>

    @php $drill = $this->drill; @endphp
    <flux:modal name="sales-tax-drill" class="max-w-2xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">
                    @if ($drill['bucket'] === 'paid')
                        {{ __('Paid (ITC) breakdown') }}
                    @else
                        {{ __('Collected on sales breakdown') }}
                    @endif
                </flux:heading>
                <flux:subheading>
                    @if ($drill['agency'])
                        {{ $drill['agency']->name }} —
                    @endif
                    {{ $startDate }} → {{ $endDate }}
                </flux:subheading>
            </div>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Document') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($drill['lines'] as $line)
                            @php $url = $this->drillUrl($line); @endphp
                            <tr data-test="tax-drill-row">
                                <td class="px-3 py-2 font-mono text-xs">{{ $line['entry_date']->toDateString() }}</td>
                                <td class="px-3 py-2">
                                    @if ($url)
                                        <a href="{{ $url }}" class="underline">{{ $line['doc_label'] }}</a>
                                    @else
                                        {{ $line['doc_label'] }}
                                    @endif
                                    @if ($line['is_reversal'])
                                        <flux:badge size="sm" color="zinc" class="ml-1">{{ __('reversal') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono @if ($line['amount_cents'] < 0) text-red-600 @endif">
                                    {{ number_format($line['amount_cents'] / 100, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-muted-foreground">{{ __('No activity in this bucket for the selected period.') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if ($drill['lines']->isNotEmpty())
                        <tfoot class="bg-muted">
                            <tr>
                                <td colspan="2" class="px-3 py-2 text-right font-semibold">{{ __('Total') }}</td>
                                <td class="px-3 py-2 text-right font-mono font-semibold" data-test="tax-drill-total">
                                    {{ number_format($drill['lines']->sum('amount_cents') / 100, 2) }}
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:dropdown align="start">
                    <flux:button size="sm" variant="filled" icon="arrow-down-tray" icon:trailing="chevron-down">{{ __('Download') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item icon="document-text" wire:click="exportDrillCsv">{{ __('CSV') }}</flux:menu.item>
                        <flux:menu.item icon="table-cells" wire:click="exportDrillXlsx">{{ __('Excel') }}</flux:menu.item>
                        <flux:menu.item icon="document" wire:click="exportDrillPdf">{{ __('PDF') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
