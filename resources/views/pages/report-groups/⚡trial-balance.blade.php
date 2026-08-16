<?php

use App\Models\ReportGroup;
use App\Services\Reporting\CombinedReportCalculator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Combined Trial Balance')] class extends Component {
    public ReportGroup $reportGroup;

    #[Url(as: 'as_of')]
    public string $asOf = '';

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
        return app(CombinedReportCalculator::class)->trialBalance(
            $this->reportGroup,
            CarbonImmutable::parse($this->asOf),
        );
    }

    #[Computed]
    public function warnings(): array
    {
        return [
            'currency' => app(CombinedReportCalculator::class)->currencyMismatches($this->reportGroup),
            'fiscal' => false,
        ];
    }

    public function exportCsv()
    {
        $rows = collect();

        foreach ($this->report['companies'] as $section) {
            $rows->push([strtoupper($section['company']['name'])]);
            foreach ($section['rows'] as $row) {
                $rows->push([$row['code'].' — '.$row['name'], CsvExporter::cents($row['debit']), CsvExporter::cents($row['credit'])]);
            }
            $rows->push(['Subtotal', CsvExporter::cents($section['total_debit']), CsvExporter::cents($section['total_credit'])]);
            $rows->push(['']);
        }

        $rows->push(['TOTAL', CsvExporter::cents($this->report['total_debit']), CsvExporter::cents($this->report['total_credit'])]);

        return app(CsvExporter::class)->stream(
            "combined-trial-balance-{$this->asOf}.csv",
            ['Account', 'Debit', 'Credit'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->combinedTrialBalance(
            "combined-trial-balance-{$this->asOf}.xlsx",
            $this->reportGroup,
            $this->report,
            $this->asOf,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.combined-trial-balance', [
            'group' => $this->reportGroup,
            'report' => $this->report,
            'asOf' => $this->asOf,
        ], "combined-trial-balance-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Trial Balance') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ $reportGroup->currency_code }} &middot; {{ __('as of') }} {{ $asOf }}</flux:subheading>
        </div>
        <div class="flex items-end gap-2">
            <flux:input type="date" wire:model.live="asOf" :label="__('As of')" class="max-w-[180px]" />
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

    <flux:text class="mb-3 text-xs text-muted-foreground">{{ __('Lists individual accounts per company so debits equal credits — line mappings do not apply here.') }}</flux:text>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->report['companies'] as $section)
                    <tr class="bg-muted"><td colspan="3" class="px-4 py-2 font-semibold">{{ $section['company']['name'] }}</td></tr>
                    @foreach ($section['rows'] as $row)
                        <tr data-test="tb-row">
                            <td class="px-4 py-1 pl-8">{{ $row['code'] }} — {{ $row['name'] }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ $row['debit'] ? number_format($row['debit'] / 100, 2) : '' }}</td>
                            <td class="px-4 py-1 text-right font-mono">{{ $row['credit'] ? number_format($row['credit'] / 100, 2) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-border">
                        <td class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium">{{ number_format($section['total_debit'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-medium">{{ number_format($section['total_credit'] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="tb-total-debit">{{ number_format($this->report['total_debit'] / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="tb-total-credit">{{ number_format($this->report['total_credit'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
