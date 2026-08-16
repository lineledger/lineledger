<?php

use App\Concerns\EmailsReport;
use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportAsOfDate;
use App\Concerns\Memorizable;
use App\Models\Company;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('AP Aging')] class extends Component
{
    use EmailsReport;
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportAsOfDate;
    use Memorizable;

    private const SORT_FIELDS = ['name', 'current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus', 'total'];

    private const DETAIL_SORT_FIELDS = ['doc_no', 'name', 'doc_date', 'due_date', 'days_overdue', 'balance'];

    public Company $company;

    #[Url(as: 'view')]
    public string $view = 'summary';

    #[Url(as: 'sort')]
    public string $sortField = 'name';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    #[Url(as: 'open_only')]
    public bool $excludeUnappliedCredits = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportAsOfDate();
        $this->applyMemorized((int) request('memorized'));

        if (! in_array($this->view, ['summary', 'detail'], true)) {
            $this->view = 'summary';
        }
    }

    protected function reportKey(): string
    {
        return 'reports.ap-aging';
    }

    /**
     * Bucket columns of the SUMMARY table; the detail table is not toggleable.
     *
     * @return array<string, string>
     */
    public function columnRegistry(): array
    {
        return [
            'current' => __('Current'),
            'b1_30' => __('1–30'),
            'b31_60' => __('31–60'),
            'b61_90' => __('61–90'),
            'b90_plus' => __('90+'),
        ];
    }

    public function updatedView(): void
    {
        if (! in_array($this->view, ['summary', 'detail'], true)) {
            $this->view = 'summary';
        }

        [$this->sortField, $this->sortDir] = $this->view === 'detail' ? ['due_date', 'asc'] : ['name', 'asc'];
    }

    public function sortBy(string $field): void
    {
        $allowed = $this->view === 'detail' ? self::DETAIL_SORT_FIELDS : self::SORT_FIELDS;

        if (! in_array($field, $allowed, true)) {
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
     * The classic per-vendor aging summary, reconciled to the AP control
     * account by OpenDocumentAgingBuilder (see that class for the guarantee).
     *
     * @return array{rows: array<int, array{contact_id: int, name: string, current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}>, totals: array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}}
     */
    #[Computed]
    public function report(): array
    {
        $report = app(OpenDocumentAgingBuilder::class)->summary(
            $this->company,
            'ap',
            CarbonImmutable::parse($this->asOf),
            $this->excludeUnappliedCredits,
        );

        $field = in_array($this->sortField, self::SORT_FIELDS, true) ? $this->sortField : 'name';
        $dirMul = $this->sortDir === 'desc' ? -1 : 1;
        $rows = $report['rows'];

        usort($rows, function ($a, $b) use ($field, $dirMul) {
            $cmp = $field === 'name'
                ? strcasecmp($a['name'], $b['name'])
                : ($a[$field] <=> $b[$field]);

            return $cmp === 0
                ? strcasecmp($a['name'], $b['name'])
                : $cmp * $dirMul;
        });

        return ['rows' => $rows, 'totals' => $report['totals']];
    }

    /**
     * Detail view: every open bill individually, grouped by bucket, plus the
     * Adjustments section keeping the grand total tied to the GL.
     *
     * @return array{buckets: array<string, array{label: string, rows: array<int, array<string, mixed>>, subtotal: int}>, adjustments: array<int, array{contact_id: int, name: string, amount: int}>, adjustments_total: int, grand_total: int}
     */
    #[Computed]
    public function detailReport(): array
    {
        $report = app(OpenDocumentAgingBuilder::class)->detail(
            $this->company,
            'ap',
            CarbonImmutable::parse($this->asOf),
            $this->excludeUnappliedCredits,
        );

        $field = in_array($this->sortField, self::DETAIL_SORT_FIELDS, true) ? $this->sortField : 'due_date';
        $dirMul = $this->sortDir === 'desc' ? -1 : 1;

        foreach ($report['buckets'] as &$bucket) {
            usort($bucket['rows'], function ($a, $b) use ($field, $dirMul) {
                $cmp = in_array($field, ['name', 'doc_no'], true)
                    ? strcasecmp($a[$field], $b[$field])
                    : ($a[$field] <=> $b[$field]);

                return $cmp === 0
                    ? strcasecmp($a['doc_no'], $b['doc_no'])
                    : $cmp * $dirMul;
            });
        }

        return $report;
    }

    #[Computed]
    public function detailHasRows(): bool
    {
        $report = $this->detailReport;

        return $report['adjustments'] !== []
            || array_sum(array_map(fn (array $bucket) => count($bucket['rows']), $report['buckets'])) > 0;
    }

    public function exportXlsx()
    {
        if ($this->view === 'detail') {
            return app(XlsxExporter::class)->agingDetail(
                "ap-aging-detail-{$this->asOf}.xlsx",
                'AP Aging Detail',
                'Vendor',
                'Bill',
                $this->company,
                $this->detailReport,
                $this->asOf,
            );
        }

        return app(XlsxExporter::class)->aging(
            "ap-aging-{$this->asOf}.xlsx",
            'AP Aging',
            'Vendor',
            $this->company,
            $this->report,
            $this->asOf,
        );
    }

    public function exportPdf()
    {
        if ($this->view === 'detail') {
            return app(PdfExporter::class)->download('pdf.reports.aging-detail', [
                'company' => $this->company,
                'title' => $this->effectiveTitle('AP Aging Detail'),
                'entityLabel' => 'Vendor',
                'docLabel' => 'Bill',
                'emptyMessage' => 'No open bills as of this date.',
                'report' => $this->detailReport,
                'asOf' => $this->asOf,
            ], "ap-aging-detail-{$this->asOf}.pdf");
        }

        return app(PdfExporter::class)->download('pdf.reports.aging', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('AP Aging'),
            'entityLabel' => 'Vendor',
            'emptyMessage' => 'No open bills as of this date.',
            'report' => $this->report,
            'asOf' => $this->asOf,
        ], "ap-aging-{$this->asOf}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle($view === 'detail' ? __('AP Aging Detail') : __('AP Aging'))"
        :subtitle="$view === 'detail' ? __('Every open bill by age.') : __('Open vendor balances by age.')"
        mode="single"
        :exports="['xlsx', 'pdf']"
        :exports-disabled="$view === 'detail' ? ! $this->detailHasRows : empty($this->report['rows'])"
        :title-editable="true"
        :memorizable="true"
        :emailable="$this->canEmailReport()"
        :print-url="$this->printReportUrl()"
    >
        <flux:radio.group wire:model.live="view" variant="segmented" :label="__('View')" data-test="aging-view-toggle">
            <flux:radio value="summary">{{ __('Summary') }}</flux:radio>
            <flux:radio value="detail">{{ __('Detail') }}</flux:radio>
        </flux:radio.group>
        <flux:switch wire:model.live="excludeUnappliedCredits" :label="__('Owing only')" />
        @if ($view === 'summary')
            <x-reports.column-picker :columns="$this->columnRegistry()" />
        @endif
    </x-reports.control-bar>

    @if ($view === 'detail')
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left"><x-sort-header field="doc_no" :current-field="$sortField" :current-dir="$sortDir" :label="__('Bill #')" /></th>
                        <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Vendor')" /></th>
                        <th class="px-4 py-2 text-left"><x-sort-header field="doc_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                        <th class="px-4 py-2 text-left"><x-sort-header field="due_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Due date')" /></th>
                        <th class="px-4 py-2 text-right"><x-sort-header field="days_overdue" :current-field="$sortField" :current-dir="$sortDir" :label="__('Days overdue')" align="right" /></th>
                        <th class="px-4 py-2 text-right"><x-sort-header field="balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Balance')" align="right" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @if (! $this->detailHasRows)
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No open bills as of this date.') }}</td></tr>
                    @endif

                    @foreach ($this->detailReport['buckets'] as $bucketKey => $bucket)
                        @if (! empty($bucket['rows']))
                            <tr class="bg-muted/50" data-test="aging-detail-bucket">
                                <td colspan="6" class="px-4 py-2 font-medium">{{ $bucket['label'] }}</td>
                            </tr>
                            @foreach ($bucket['rows'] as $row)
                                <tr data-test="aging-detail-row">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('bills.show', ['company' => $company->slug, 'bill' => $row['doc_id']]) }}" class="underline" wire:navigate>{{ $row['doc_no'] }}</a>
                                    </td>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['contact_id'], 'kind' => 'ap']) }}" class="underline">{{ $row['name'] }}</a>
                                    </td>
                                    <td class="px-4 py-2">{{ $row['doc_date'] }}</td>
                                    <td class="px-4 py-2">{{ $row['due_date'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ $row['days_overdue'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-muted/30">
                                <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Total :bucket', ['bucket' => $bucket['label']]) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($bucket['subtotal'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach

                    @if (! empty($this->detailReport['adjustments']))
                        <tr class="bg-muted/50" data-test="aging-detail-adjustments">
                            <td colspan="6" class="px-4 py-2 font-medium">{{ __('Adjustments (credits, unapplied payments & ledger entries)') }}</td>
                        </tr>
                        @foreach ($this->detailReport['adjustments'] as $adjustment)
                            <tr data-test="aging-detail-adjustment-row">
                                <td class="px-4 py-2 text-muted-foreground">—</td>
                                <td class="px-4 py-2" colspan="4">
                                    @if ($adjustment['contact_id'] > 0)
                                        <a href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $adjustment['contact_id'], 'kind' => 'ap']) }}" class="underline">{{ $adjustment['name'] }}</a>
                                    @else
                                        <span class="text-muted-foreground" data-test="ap-aging-unattributed">{{ $adjustment['name'] }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($adjustment['amount'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-muted/30">
                            <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Total adjustments') }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($this->detailReport['adjustments_total'] / 100, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
                @if ($this->detailHasRows)
                    <tfoot class="bg-muted">
                        <tr>
                            <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Grand total') }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold" data-test="aging-detail-grand-total">{{ number_format($this->detailReport['grand_total'] / 100, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @else
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Vendor')" /></th>
                    @foreach ($this->columnRegistry() as $bucketKey => $bucketLabel)
                        @if ($this->columnVisible($bucketKey))
                            <th class="px-4 py-2 text-right"><x-sort-header :field="$bucketKey" :current-field="$sortField" :current-dir="$sortDir" :label="$bucketLabel" align="right" /></th>
                        @endif
                    @endforeach
                    <th class="px-4 py-2 text-right"><x-sort-header field="total" :current-field="$sortField" :current-dir="$sortDir" :label="__('Total')" align="right" /></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->report['rows'] as $row)
                    <tr data-test="ap-aging-row">
                        <td class="px-4 py-2">
                            @if ($row['contact_id'] > 0)
                                <a
                                    href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $row['contact_id'], 'kind' => 'ap']) }}"
                                    class="underline"
                                    data-test="ap-aging-contact-link"
                                >{{ $row['name'] }}</a>
                            @else
                                <span class="text-muted-foreground" data-test="ap-aging-unattributed">{{ $row['name'] }}</span>
                            @endif
                        </td>
                        @foreach (array_keys($this->columnRegistry()) as $bucketKey)
                            @if ($this->columnVisible($bucketKey))
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($row[$bucketKey] / 100, 2) }}</td>
                            @endif
                        @endforeach
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($row['total'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $this->visibleColumnCount(fixed: 2) }}" class="px-4 py-8 text-center text-muted-foreground">{{ __('No open bills as of this date.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->report['rows']))
                <tfoot class="bg-muted">
                    <tr>
                        <td class="px-4 py-2 text-right font-medium">{{ __('Totals') }}</td>
                        @foreach (array_keys($this->columnRegistry()) as $bucketKey)
                            @if ($this->columnVisible($bucketKey))
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($this->report['totals'][$bucketKey] / 100, 2) }}</td>
                            @endif
                        @endforeach
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($this->report['totals']['total'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @endif
</section>
