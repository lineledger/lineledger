<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Statement of Changes in Net Assets')] class extends Component
{
    use HasCustomReportHeader;
    use HasReportDateRange;
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
        return 'reports.statement-of-changes-in-net-assets';
    }

    /**
     * @return array{
     *   classes: array<string, array{label: string, opening: int, excess: int, other: int, closing: int}>,
     *   total: array{opening: int, excess: int, other: int, closing: int},
     *   reconciles: bool,
     * }
     */
    #[Computed]
    public function report(): array
    {
        return app(ReportCalculator::class)->netAssetRollForward(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    public function exportCsv()
    {
        $r = $this->report;
        $rows = collect();

        foreach ($r['classes'] as $class) {
            $rows->push([
                $class['label'],
                CsvExporter::cents($class['opening']),
                CsvExporter::cents($class['excess']),
                CsvExporter::cents($class['other']),
                CsvExporter::cents($class['closing']),
            ]);
        }

        $rows->push([
            'Total net assets',
            CsvExporter::cents($r['total']['opening']),
            CsvExporter::cents($r['total']['excess']),
            CsvExporter::cents($r['total']['other']),
            CsvExporter::cents($r['total']['closing']),
        ]);

        return app(CsvExporter::class)->stream(
            "statement-of-changes-in-net-assets-{$this->startDate}-{$this->endDate}.csv",
            ['Net asset class', 'Opening', 'Excess (deficiency)', 'Other changes', 'Closing'],
            $rows,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.statement-of-changes-in-net-assets', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'title' => $this->effectiveTitle('Statement of Changes in Net Assets'),
        ], "statement-of-changes-in-net-assets-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Statement of Changes in Net Assets'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate"
        mode="range"
        :comparison="false"
        :exports="['csv', 'pdf']"
        :title-editable="true"
        :memorizable="true"
    />

    @php $r = $this->report; @endphp

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Net asset class') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Opening balance') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Excess (deficiency)') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Other changes') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Closing balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['classes'] as $key => $class)
                    <tr data-test="socna-row-{{ $key }}">
                        <td class="px-4 py-2">{{ $class['label'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($class['opening'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($class['excess'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($class['other'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($class['closing'] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-3 font-semibold">{{ __('Total net assets') }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="socna-total-opening">{{ number_format($r['total']['opening'] / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold">{{ number_format($r['total']['excess'] / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold">{{ number_format($r['total']['other'] / 100, 2) }}</td>
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="socna-total-closing">{{ number_format($r['total']['closing'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless ($r['reconciles'])
        <flux:text class="mt-2 text-red-600">{{ __('Statement does not reconcile — review net-asset postings.') }}</flux:text>
    @endunless
</section>
