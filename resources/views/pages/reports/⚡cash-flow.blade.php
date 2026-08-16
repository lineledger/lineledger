<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportChart;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportNumberFormat;
use App\Concerns\Memorizable;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\CashFlowBucket;
use App\Support\Reporting\ReportChartBuilder;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash Flow Statement')] class extends Component {
    use EmailsReport;
    use HasCustomReportHeader;
    use HasReportChart;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportNumberFormat;
    use Memorizable;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.cash-flow';
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
        $prior = $this->comparisonRange();

        return app(ReportCalculator::class)->cashFlow(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $prior !== null,
            $prior[0] ?? null,
            $prior[1] ?? null,
        );
    }

    /**
     * Chart series for this report (default chart first). See ReportChartBuilder.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function chartData(): array
    {
        return ReportChartBuilder::cashFlow($this->report, $this->chartContext());
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();

        $headers = $this->showComparison
            ? ['Section', 'Code', 'Line', 'Current', 'Prior']
            : ['Section', 'Code', 'Line', 'Amount'];

        $cents = fn (int $c): string => CsvExporter::cents($c);

        $activity = function (string $key, string $label, int $total, int $priorTotal) use (&$rows, $r, $cents): void {
            $rows->push([strtoupper($label)]);

            if ($key === 'operating') {
                $ni = ['', '', 'Net income', $cents($r['net_income'])];
                if ($this->showComparison) {
                    $ni[] = $cents($r['prior_net_income']);
                }
                $rows->push($ni);
            }

            foreach ($r[$key] as $block) {
                if ($block['type'] === 'section') {
                    $rows->push(['', '', $block['name']]);
                }
                foreach ($block['rows'] as $a) {
                    $line = ['', $a['code'], $a['name'], $cents($a['current'])];
                    if ($this->showComparison) {
                        $line[] = $cents($a['prior']);
                    }
                    $rows->push($line);
                }
                if ($block['type'] === 'section') {
                    $sub = ['', '', 'Total '.$block['name'], $cents($block['subtotal'])];
                    if ($this->showComparison) {
                        $sub[] = $cents($block['prior_subtotal']);
                    }
                    $rows->push($sub);
                }
            }

            $totalRow = ['Total '.$label, '', '', $cents($total)];
            if ($this->showComparison) {
                $totalRow[] = $cents($priorTotal);
            }
            $rows->push($totalRow);
            $rows->push(['']);
        };

        $activity('operating', 'Operating Activities', $r['total_operating'], $r['prior_total_operating']);
        $activity('investing', 'Investing Activities', $r['total_investing'], $r['prior_total_investing']);
        $activity('financing', 'Financing Activities', $r['total_financing'], $r['prior_total_financing']);

        $push = function (string $label, int $current, int $prior) use (&$rows, $cents): void {
            $row = [$label, '', '', $cents($current)];
            if ($this->showComparison) {
                $row[] = $cents($prior);
            }
            $rows->push($row);
        };

        $push('NET CHANGE IN CASH', $r['net_change'], $r['prior_net_change']);
        $push('Cash at beginning of period', $r['cash_beginning'], $r['prior_cash_beginning']);
        $push('Cash at end of period', $r['cash_ending'], $r['prior_cash_ending']);

        return app(CsvExporter::class)->stream(
            "cash-flow-{$this->startDate}-{$this->endDate}.csv",
            $headers,
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->cashFlow(
            "cash-flow-{$this->startDate}-{$this->endDate}.xlsx",
            $this->company,
            $this->report,
            $this->startDate,
            $this->endDate,
            $this->showComparison,
            $this->numberFormat->xlsxMoneyFormat(),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.cash-flow', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'showComparison' => $this->showComparison,
            'comparisonNote' => $this->comparisonNote,
            'title' => $this->effectiveTitle('Cash Flow Statement'),
            'fmt' => $this->numberFormat,
        ], "cash-flow-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

@php $showComparison = $this->showComparison; @endphp
<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Cash Flow Statement'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.$this->comparisonNote.($this->numberFormat->unitsSuffix() ?? '')"
        mode="range"
        :comparison="true"
        :number-format="true"
        :sections-route="route('reports.cash-flow.sections', ['company' => $company->slug])"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    />

    <x-reports.chart-panel
        class="mb-6"
        :charts="$this->chartData()"
        :title="$this->effectiveTitle(__('Cash Flow Statement'))"
        :period="$startDate.' '.__('to').' '.$endDate.$this->comparisonNote"
    />

    @php
        $fmt = $this->numberFormat;
        $colspan = $showComparison ? 3 : 2;
    @endphp

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Line') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Current') }}</th>
                    @if ($showComparison)
                        <th class="px-4 py-2 text-right">{{ __('Prior') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($this->activityLabels() as $key => $label)
                    @php
                        $blocks = $this->report[$key];
                        $total = $this->report["total_{$key}"];
                        $priorTotal = $this->report["prior_total_{$key}"];
                    @endphp

                    <tr class="bg-muted"><td colspan="{{ $colspan }}" class="px-4 py-2 font-semibold">{{ __($label) }}</td></tr>

                    @if ($key === 'operating')
                        <tr data-test="cf-net-income">
                            <td class="px-4 py-1 pl-8">{{ __('Net income') }}</td>
                            <td class="px-4 py-1 text-right font-mono {{ $fmt->cssClass($this->report['net_income']) }}">{{ $fmt->format($this->report['net_income']) }}</td>
                            @if ($showComparison)
                                <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($this->report['prior_net_income']) }}</td>
                            @endif
                        </tr>
                    @endif

                    @foreach ($blocks as $block)
                        @if ($block['type'] === 'section')
                            <tr data-test="cf-section-header"><td colspan="{{ $colspan }}" class="px-4 py-1 pl-6 font-medium text-muted-foreground">{{ $block['name'] }}</td></tr>
                        @endif
                        @foreach ($block['rows'] as $a)
                            <tr data-test="cf-row">
                                <td class="px-4 py-1 {{ $block['type'] === 'section' ? 'pl-12' : 'pl-8' }}">
                                    @if (! empty($a['id']))
                                        <a href="{{ route('reports.transactions', ['company' => $company->slug, 'account' => $a['id'], 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-account">{{ $a['code'] }} — {{ $a['name'] }}</a>
                                    @else
                                        {{ $a['code'] }} — {{ $a['name'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-1 text-right font-mono {{ $fmt->cssClass($a['current']) }}">{{ $fmt->format($a['current']) }}</td>
                                @if ($showComparison)
                                    <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($a['prior']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        @if ($block['type'] === 'section')
                            <tr class="border-t border-border">
                                <td class="px-4 py-1 pl-8 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="cf-section-subtotal-{{ $block['id'] }}">{{ $fmt->format($block['subtotal']) }}</td>
                                @if ($showComparison)
                                    <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($block['prior_subtotal']) }}</td>
                                @endif
                            </tr>
                        @endif
                    @endforeach

                    <tr class="border-t border-border">
                        <td class="px-4 py-2 font-medium">{{ __('Net cash from') }} {{ __($label) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium {{ $fmt->cssClass($total) }}" data-test="cf-total-{{ $key }}">{{ $fmt->format($total) }}</td>
                        @if ($showComparison)
                            <td class="px-4 py-2 text-right font-mono font-medium text-muted-foreground">{{ $fmt->format($priorTotal) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ __('Net change in cash') }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold {{ $fmt->cssClass($this->report['net_change']) }}" data-test="cf-net-change">{{ $fmt->format($this->report['net_change']) }}</td>
                    @if ($showComparison)
                        <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $fmt->format($this->report['prior_net_change']) }}</td>
                    @endif
                </tr>
                <tr>
                    <td class="px-4 py-1 pl-4">{{ __('Cash at beginning of period') }}</td>
                    <td class="px-4 py-1 text-right font-mono {{ $fmt->cssClass($this->report['cash_beginning']) }}">{{ $fmt->format($this->report['cash_beginning']) }}</td>
                    @if ($showComparison)
                        <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ $fmt->format($this->report['prior_cash_beginning']) }}</td>
                    @endif
                </tr>
                <tr>
                    <td class="px-4 py-2 pl-4 font-semibold">{{ __('Cash at end of period') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold {{ $fmt->cssClass($this->report['cash_ending']) }}" data-test="cf-cash-ending">{{ $fmt->format($this->report['cash_ending']) }}</td>
                    @if ($showComparison)
                        <td class="px-4 py-2 text-right font-mono font-semibold text-muted-foreground">{{ $fmt->format($this->report['prior_cash_ending']) }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>

    @unless ($this->report['reconciles'])
        <flux:text class="mt-3 text-red-600">{{ __('Out of balance — cash at end of period differs from the computed change by') }} {{ $fmt->format(abs($this->report['cash_ending'] - ($this->report['cash_beginning'] + $this->report['net_change']))) }}</flux:text>
    @endunless
</section>
