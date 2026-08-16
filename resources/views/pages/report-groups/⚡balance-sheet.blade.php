<?php

use App\Models\ReportGroup;
use App\Services\Reporting\CombinedReportCalculator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Combined Balance Sheet')] class extends Component {
    public ReportGroup $reportGroup;

    #[Url(as: 'as_of')]
    public string $asOf = '';

    #[Url]
    public bool $byCompany = false;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('view', $reportGroup);

        $this->reportGroup = $reportGroup;

        $tzNow = \Illuminate\Support\Facades\Auth::user()?->currentCompany?->currentDateTime() ?? now();

        if ($this->asOf === '') {
            $this->asOf = $tzNow->toDateString();
        }
    }

    #[Computed]
    public function report(): array
    {
        return app(CombinedReportCalculator::class)->balanceSheet(
            $this->reportGroup,
            CarbonImmutable::parse($this->asOf),
        );
    }

    /** Statement vocabulary; non-profit only when every member company is. */
    #[Computed]
    public function labels(): StatementLabels
    {
        return StatementLabels::forGroup($this->reportGroup->companies);
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

        $emit = function (string $section, int $total, ?string $displayName = null) use (&$rows, $r) {
            $name = $displayName ?? ucfirst($section);
            $rows->push([strtoupper($name)]);
            foreach ($r[$section] as $group) {
                $rows->push(['', $group['label']]);
                foreach ($group['blocks'] as $block) {
                    if ($block['type'] === 'section') {
                        $rows->push(['', '', $block['name']]);
                    }
                    foreach ($block['rows'] as $line) {
                        $name = ($block['type'] === 'section' ? '    ' : '').$line['name'];
                        $rows->push(['', '', $name, CsvExporter::cents($line['balance'])]);
                    }
                    if ($block['type'] === 'section') {
                        $rows->push(['', '', 'Total '.$block['name'], CsvExporter::cents($block['subtotal'])]);
                    }
                }
            }
            $rows->push(['', 'Total '.$name, '', CsvExporter::cents($total)]);
            $rows->push(['']);
        };

        $emit('assets', $r['total_assets']);
        $emit('liabilities', $r['total_liabilities']);
        $emit('equity', $r['total_equity'], $this->labels->equityShort());
        if ($r['retained_earnings_prior'] !== 0) {
            $rows->push(['', $this->labels->retainedEarningsPriorRow(), '', CsvExporter::cents($r['retained_earnings_prior'])]);
        }
        $rows->push(['', $this->labels->netIncomeYtd(), '', CsvExporter::cents($r['net_income_ytd'])]);
        $rows->push(['', strtoupper($this->labels->totalLiabilitiesAndEquity()), '', CsvExporter::cents($r['total_le'])]);

        return app(CsvExporter::class)->stream(
            "combined-balance-sheet-{$this->asOf}.csv",
            ['Section', 'Subtype', 'Line', 'Amount'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->combinedBalanceSheet(
            "combined-balance-sheet-{$this->asOf}.xlsx",
            $this->reportGroup,
            $this->report,
            $this->asOf,
            $this->byCompany,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.combined-balance-sheet', [
            'group' => $this->reportGroup,
            'report' => $this->report,
            'asOf' => $this->asOf,
            'labels' => $this->labels,
        ], "combined-balance-sheet-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Balance Sheet') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ $reportGroup->currency_code }} &middot; {{ __('as of') }} {{ $asOf }}</flux:subheading>
        </div>
        <div class="flex items-end gap-2">
            <flux:input type="date" wire:model.live="asOf" :label="__('As of')" class="max-w-[180px]" />
            <flux:switch wire:model.live="byCompany" :label="__('By company')" />
            @can('update', $reportGroup)
                <flux:button icon="cog-6-tooth" variant="ghost" :href="route('report-groups.balance-sheet.sections', $reportGroup)" wire:navigate data-test="sections-config-link">{{ __('Sections') }}</flux:button>
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
                @foreach (['assets' => __('Assets'), 'liabilities' => __('Liabilities'), 'equity' => $this->labels->equityShort()] as $key => $label)
                    <tr class="bg-muted"><td colspan="{{ $span }}" class="px-4 py-2 font-semibold">{{ $label }}</td></tr>
                    @forelse ($this->report[$key] as $group)
                        <tr><td colspan="{{ $span }}" class="px-4 pt-2 text-xs uppercase tracking-wide text-muted-foreground">{{ $group['label'] }}</td></tr>
                        @foreach ($group['blocks'] as $block)
                            @if ($block['type'] === 'section')
                                <tr data-test="cbs-section-header"><td colspan="{{ $span }}" class="px-4 py-1 pl-8 font-medium text-muted-foreground">{{ $block['name'] }}</td></tr>
                            @endif
                            @foreach ($block['rows'] as $line)
                                <tr data-test="bs-row">
                                    <td class="px-4 py-1 {{ $block['type'] === 'section' ? 'pl-12' : 'pl-8' }}">{{ $line['name'] }}</td>
                                    @if ($byCompany)
                                        @foreach ($companies as $c)
                                            <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($line['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                        @endforeach
                                    @endif
                                    <td class="px-4 py-1 text-right font-mono">{{ number_format($line['balance'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                            @if ($block['type'] === 'section')
                                <tr class="border-t border-border">
                                    <td class="px-4 py-1 pl-10 text-sm italic text-muted-foreground">{{ __('Total') }} {{ $block['name'] }}</td>
                                    @if ($byCompany)
                                        @foreach ($companies as $c)
                                            <td class="px-4 py-1 text-right font-mono italic text-muted-foreground">{{ number_format(($block['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                        @endforeach
                                    @endif
                                    <td class="px-4 py-1 text-right font-mono italic text-muted-foreground" data-test="cbs-section-subtotal-{{ $block['id'] }}">{{ number_format($block['subtotal'] / 100, 2) }}</td>
                                </tr>
                            @endif
                        @endforeach
                        @if ($group['has_section'])
                            <tr class="border-t border-border">
                                <td class="px-4 py-1 pl-8 font-medium">{{ __('Total') }} {{ $group['label'] }}</td>
                                @if ($byCompany)
                                    @foreach ($companies as $c)
                                        <td class="px-4 py-1 text-right font-mono font-medium">{{ number_format(($group['by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                    @endforeach
                                @endif
                                <td class="px-4 py-1 text-right font-mono font-medium">{{ number_format($group['subtotal'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $span }}" class="px-4 py-1 pl-8 text-muted-foreground">{{ __('No accounts.') }}</td></tr>
                    @endforelse

                    @if ($key === 'equity' && $this->report['retained_earnings_prior'] !== 0)
                        <tr data-test="bs-retained-earnings-prior">
                            <td class="px-4 py-1 pl-8 italic">{{ $this->labels->retainedEarningsPriorRow() }}</td>
                            @if ($byCompany)
                                @foreach ($companies as $c)
                                    <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($this->report['retained_earnings_prior_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                @endforeach
                            @endif
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($this->report['retained_earnings_prior'] / 100, 2) }}</td>
                        </tr>
                    @endif

                    @if ($key === 'equity' && $this->report['net_income_ytd'] !== 0)
                        <tr>
                            <td class="px-4 py-1 pl-8 italic">{{ $this->labels->netIncomeYtd() }}</td>
                            @if ($byCompany)
                                @foreach ($companies as $c)
                                    <td class="px-4 py-1 text-right font-mono text-muted-foreground">{{ number_format(($this->report['net_income_ytd_by_company'][$c['id']] ?? 0) / 100, 2) }}</td>
                                @endforeach
                            @endif
                            <td class="px-4 py-1 text-right font-mono">{{ number_format($this->report['net_income_ytd'] / 100, 2) }}</td>
                        </tr>
                    @endif

                    <tr class="border-t border-border">
                        <td class="px-4 py-2 font-medium">{{ __('Total') }} {{ $label }}</td>
                        @if ($byCompany)<td colspan="{{ count($companies) }}"></td>@endif
                        <td class="px-4 py-2 text-right font-mono font-medium" data-test="bs-total-{{ $key }}">
                            @if ($key === 'equity')
                                {{ number_format(($this->report['total_equity'] + $this->report['retained_earnings_prior'] + $this->report['net_income_ytd']) / 100, 2) }}
                            @else
                                {{ number_format($this->report['total_'.$key] / 100, 2) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ $this->labels->totalLiabilitiesAndEquity() }}</td>
                    @if ($byCompany)<td colspan="{{ count($companies) }}"></td>@endif
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="bs-total-le">{{ number_format($this->report['total_le'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($this->report['total_assets'] !== $this->report['total_le'])
        <flux:text class="mt-3 text-red-600">{{ __('Out of balance — difference') }} {{ number_format(abs($this->report['total_assets'] - $this->report['total_le']) / 100, 2) }}</flux:text>
    @endif
</section>
