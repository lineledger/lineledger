<?php

use App\Models\ReportGroup;
use App\Services\Reporting\CombinedReportCalculator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\CashFlowBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Combined Cash Flow Statement')] class extends Component {
    public ReportGroup $reportGroup;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url]
    public bool $byCompany = false;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('view', $reportGroup);

        $this->reportGroup = $reportGroup;

        $tzNow = \Illuminate\Support\Facades\Auth::user()?->currentCompany?->currentDateTime() ?? now();

        if ($this->startDate === '') {
            $this->startDate = $tzNow->startOfYear()->toDateString();
        }
        if ($this->endDate === '') {
            $this->endDate = $tzNow->toDateString();
        }
    }

    /**
     * @return array<string, string>
     */
    public function activityLabels(): array
    {
        return CashFlowBucket::labels();
    }

    #[Computed]
    public function report(): array
    {
        return app(CombinedReportCalculator::class)->cashFlow(
            $this->reportGroup,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    #[Computed]
    public function warnings(): array
    {
        $calc = app(CombinedReportCalculator::class);

        return [
            'currency' => $calc->currencyMismatches($this->reportGroup),
            'fiscal' => $calc->hasMixedFiscalYears($this->reportGroup),
        ];
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();
        $cents = fn (int $c): string => CsvExporter::cents($c);

        $activity = function (string $key, string $label, int $total) use (&$rows, $r, $cents): void {
            $rows->push([strtoupper($label)]);

            if ($key === 'operating') {
                $rows->push(['', 'Net income', $cents($r['net_income'])]);
            }

            foreach ($r[$key] as $block) {
                if ($block['type'] === 'section') {
                    $rows->push(['', $block['name']]);
                }
                foreach ($block['rows'] as $line) {
                    $name = ($block['type'] === 'section' ? '    ' : '').$line['name'];
                    $rows->push(['', $name, $cents($line['current'])]);
                }
                if ($block['type'] === 'section') {
                    $rows->push(['', 'Total '.$block['name'], $cents($block['subtotal'])]);
                }
            }

            $rows->push(['Total '.$label, '', $cents($total)]);
            $rows->push(['']);
        };

        $activity('operating', 'Operating Activities', $r['total_operating']);
        $activity('investing', 'Investing Activities', $r['total_investing']);
        $activity('financing', 'Financing Activities', $r['total_financing']);

        $rows->push(['NET CHANGE IN CASH', '', $cents($r['net_change'])]);
        $rows->push(['Cash at beginning of period', '', $cents($r['cash_beginning'])]);
        $rows->push(['Cash at end of period', '', $cents($r['cash_ending'])]);

        return app(CsvExporter::class)->stream(
            "combined-cash-flow-{$this->startDate}-{$this->endDate}.csv",
            ['Section', 'Line', 'Amount'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->combinedCashFlow(
            "combined-cash-flow-{$this->startDate}-{$this->endDate}.xlsx",
            $this->reportGroup,
            $this->report,
            $this->startDate,
            $this->endDate,
            $this->byCompany,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.combined-cash-flow', [
            'group' => $this->reportGroup,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], "combined-cash-flow-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Cash Flow Statement') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ $reportGroup->currency_code }} &middot; {{ $startDate }} {{ __('to') }} {{ $endDate }}</flux:subheading>
        </div>
        <div class="flex items-end gap-2">
            <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
            <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
            <flux:switch wire:model.live="byCompany" :label="__('By company')" />
            @can('update', $reportGroup)
                <flux:button icon="cog-6-tooth" variant="ghost" :href="route('report-groups.cash-flow.sections', $reportGroup)" wire:navigate data-test="sections-config-link">{{ __('Sections') }}</flux:button>
            @endcan
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

    @include('pages.report-groups._nav', ['reportGroup' => $reportGroup])

    @include('pages.report-groups._warnings', ['warnings' => $this->warnings])

    @php($companies = $this->report['companies'])
    @php($span = $byCompany ? count($companies) + 2 : 2)

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Line') }}</th>
                    @if ($byCompany)
                        @foreach ($companies as $c)
                            <th class="px-4 py-2 text-right">{{ $c['name'] }}</th>
                        @endforeach
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Combined') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->activityLabels() as $key => $label)
                    @php($blocks = $this->report[$key])
                    <tr class="bg-muted"><td colspan="{{ $span }}" class="px-4 py-2 font-semibold">{{ __($label) }}</td></tr>

                    @if ($key === 'operating')
                        <tr data-test="cf-net-income">
                            <td class="px-4 py-1 pl-8">{{ __('Net income') }}</td>
                            @if ($byCompany)
                                @foreach ($companies as $c)
                                    <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($this->report['net_income_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                @endforeach
                            @endif
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($this->report['net_income'] / 100, 2) }}</td>
                        </tr>
                    @endif

                    @foreach ($blocks as $block)
                        @if ($block['type'] === 'section')
                            <tr data-test="ccf-section-header"><td colspan="{{ $span }}" class="px-4 py-1 pl-6 font-medium text-muted-foreground">{{ $block['name'] }}</td></tr>
                        @endif
                        @foreach ($block['rows'] as $line)
                            <tr data-test="cf-row">
                                <td class="px-4 py-1 {{ $block['type'] === 'section' ? 'pl-12' : 'pl-8' }}">{{ $line['name'] }}</td>
                                @if ($byCompany)
                                    @foreach ($companies as $c)
                                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($line['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                    @endforeach
                                @endif
                                <td class="px-4 py-1 text-right font-mono {{ $line['current'] < 0 ? 'text-red-600' : '' }}">{{ number_format($line['current'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                        @if ($block['type'] === 'section')
                            <tr class="border-t border-border">
                                <td class="px-4 py-1 pl-8 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                @if ($byCompany)
                                    @foreach ($companies as $c)
                                        <td class="px-4 py-1 text-right font-mono italic text-muted-foreground">{{ number_format(($block['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                    @endforeach
                                @endif
                                <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="ccf-section-subtotal-{{ $block['id'] }}">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach

                    <tr class="border-t border-border">
                        <td class="px-4 py-2 font-medium">{{ __('Net cash from') }} {{ __($label) }}</td>
                        @if ($byCompany)
                            @foreach ($companies as $c)
                                <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground" data-test="cf-total-{{ $key }}-{{ $c['id'] }}">{{ number_format(($this->report['total_'.$key.'_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                            @endforeach
                        @endif
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="cf-total-{{ $key }}">{{ number_format($this->report['total_'.$key] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ __('Net change in cash') }}</td>
                    @if ($byCompany)
                        @foreach ($companies as $c)
                            @php($companyNetChange = $this->report['net_change_by_company'][$c['id']] ?? 0)
                            <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground @if ($companyNetChange < 0) text-red-600 @endif" data-test="cf-net-change-{{ $c['id'] }}">{{ number_format($companyNetChange / 100, 2) }}</td>
                        @endforeach
                    @endif
                    <td class="px-4 py-3 text-right font-mono font-semibold @if ($this->report['net_change'] < 0) text-red-600 @endif" data-test="cf-net-change">{{ number_format($this->report['net_change'] / 100, 2) }}</td>
                </tr>
                <tr>
                    <td class="px-4 py-1 pl-4">{{ __('Cash at beginning of period') }}</td>
                    @if ($byCompany)
                        @foreach ($companies as $c)
                            <td class="px-4 py-1 text-right font-mono text-muted-foreground" data-test="cf-cash-beginning-{{ $c['id'] }}">{{ number_format(($this->report['cash_beginning_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                        @endforeach
                    @endif
                    <td class="px-4 py-1 text-right font-mono">{{ number_format($this->report['cash_beginning'] / 100, 2) }}</td>
                </tr>
                <tr>
                    <td class="px-4 py-2 pl-4 font-semibold">{{ __('Cash at end of period') }}</td>
                    @if ($byCompany)
                        @foreach ($companies as $c)
                            <td class="px-4 py-2 text-right font-mono font-semibold text-muted-foreground" data-test="cf-cash-ending-{{ $c['id'] }}">{{ number_format(($this->report['cash_ending_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                        @endforeach
                    @endif
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="cf-cash-ending">{{ number_format($this->report['cash_ending'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless ($this->report['reconciles'])
        <flux:text class="mt-3 text-red-600">{{ __('Out of balance — only mapped accounts are included. Difference') }} {{ number_format(abs($this->report['cash_ending'] - ($this->report['cash_beginning'] + $this->report['net_change'])) / 100, 2) }}</flux:text>
    @endunless

    @if ($byCompany)
        @php($unreconciled = collect($companies)->filter(fn (array $c): bool => ! ($this->report['reconciles_by_company'][$c['id']] ?? true)))
        @if ($unreconciled->isNotEmpty())
            <flux:text class="mt-3 text-red-600" data-test="cf-unreconciled-companies">{{ __('Columns that do not reconcile (only mapped accounts are included):') }} {{ $unreconciled->pluck('name')->join(', ') }}</flux:text>
        @endif
    @endif
</section>
