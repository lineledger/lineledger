<?php

use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\ComparisonRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchases by Item')] class extends Component {
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportDimensions;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
    }

    /** @return array<string, string> */
    public function columnRegistry(): array
    {
        $cols = ['qty' => __('Qty')];

        if ($this->showComparison()) {
            $cols['prior_qty'] = __('Prior Qty');
            $cols['qty_change'] = __('Qty Change');
            $cols['prior'] = __('Prior');
            $cols['change'] = __('Change');
            $cols['change_pct'] = __('% Change');
        }

        return $cols;
    }

    /**
     * @return Collection<int, ComparisonRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        $builder = app(SalesPurchaseReportBuilder::class);

        $current = $builder->purchasesByDimension(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            'item',
            $this->effectiveClassId(),
            $this->effectiveLocationId(),
        );

        $prior = collect();

        if ($this->showComparison()) {
            [$priorStart, $priorEnd] = $this->comparisonRange();

            $prior = $builder->purchasesByDimension(
                $this->company,
                $priorStart,
                $priorEnd,
                'item',
                $this->effectiveClassId(),
                $this->effectiveLocationId(),
            );
        }

        return $builder->mergeComparison($current, $prior);
    }

    public function exportCsv()
    {
        $qty = fn (float $q): string => rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');

        if ($this->showComparison()) {
            $headers = ['Item', 'Qty', 'Prior Qty', 'Qty Change', 'Purchases', 'Prior', 'Change', '% Change'];
            $pct = fn (?float $p): string => $p === null ? '' : number_format($p, 1).'%';
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $qty($r->qty), $qty($r->priorQty), $qty($r->qtyChange()), CsvExporter::cents($r->amountCents), CsvExporter::cents($r->priorAmountCents), CsvExporter::cents($r->changeCents()), $pct($r->changePct())]);
            $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $rows->push(['Total', '', '', '', CsvExporter::cents($curTotal), CsvExporter::cents($priorTotal), CsvExporter::cents($curTotal - $priorTotal), '']);
        } else {
            $headers = ['Item', 'Qty', 'Purchases'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $qty($r->qty), CsvExporter::cents($r->amountCents)]);
            $rows->push(['Total', '', CsvExporter::cents((int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents))]);
        }

        return app(CsvExporter::class)->stream("purchases-by-item-{$this->startDate}-{$this->endDate}.csv", $headers, $rows);
    }

    public function exportXlsx()
    {
        $qty = fn (float $q): string => rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');
        $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);

        if ($this->showComparison()) {
            $pct = fn (?float $p): string => $p === null ? '' : number_format($p, 1).'%';
            $headers = ['Item', 'Qty', 'Prior Qty', 'Qty Change', 'Purchases', 'Prior', 'Change', '% Change'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $qty($r->qty), $qty($r->priorQty), $qty($r->qtyChange()), $r->amountCents, $r->priorAmountCents, $r->changeCents(), $pct($r->changePct())])->all();
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $totals = ['Total', '', '', '', $curTotal, $priorTotal, $curTotal - $priorTotal, ''];
            $moneyColumns = [5, 6, 7];
            $columnWidths = [1 => 32, 2 => 10, 3 => 10, 4 => 10, 5 => 16, 6 => 16, 7 => 16, 8 => 12];
        } else {
            $headers = ['Item', 'Qty', 'Purchases'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $qty($r->qty), $r->amountCents])->all();
            $totals = ['Total', '', $curTotal];
            $moneyColumns = [3];
            $columnWidths = [1 => 32, 2 => 12, 3 => 16];
        }

        return app(XlsxExporter::class)->listTable(
            "purchases-by-item-{$this->startDate}-{$this->endDate}.xlsx",
            'Purchases by Item',
            $this->effectiveTitle('Purchases by Item'),
            $this->company,
            ["{$this->startDate} to {$this->endDate}".$this->comparisonNote()],
            $headers,
            $rows,
            moneyColumns: $moneyColumns,
            columnWidths: $columnWidths,
            totals: $totals,
        );
    }

    public function exportPdf()
    {
        $qty = fn (float $q): string => rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.');
        $money = fn (int $c): string => number_format($c / 100, 2);
        $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);

        if ($this->showComparison()) {
            $pct = fn (?float $p): string => $p === null ? '—' : number_format($p, 1).'%';
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $headers = [['label' => 'Item'], ['label' => 'Qty', 'num' => true], ['label' => 'Prior Qty', 'num' => true], ['label' => 'Qty Change', 'num' => true], ['label' => 'Purchases', 'num' => true], ['label' => 'Prior', 'num' => true], ['label' => 'Change', 'num' => true], ['label' => '% Change', 'num' => true]];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [
                ['value' => $r->label],
                ['value' => $qty($r->qty), 'num' => true],
                ['value' => $qty($r->priorQty), 'num' => true],
                ['value' => $qty($r->qtyChange()), 'num' => true],
                ['value' => $money($r->amountCents), 'num' => true],
                ['value' => $money($r->priorAmountCents), 'num' => true],
                ['value' => $money($r->changeCents()), 'num' => true],
                ['value' => $pct($r->changePct()), 'num' => true],
            ])->all();
            $totals = [['value' => 'Total'], ['value' => '', 'num' => true], ['value' => '', 'num' => true], ['value' => '', 'num' => true], ['value' => $money($curTotal), 'num' => true], ['value' => $money($priorTotal), 'num' => true], ['value' => $money($curTotal - $priorTotal), 'num' => true], ['value' => '', 'num' => true]];
        } else {
            $headers = [['label' => 'Item'], ['label' => 'Qty', 'num' => true], ['label' => 'Purchases', 'num' => true]];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [['value' => $r->label], ['value' => $qty($r->qty), 'num' => true], ['value' => $money($r->amountCents), 'num' => true]])->all();
            $totals = [['value' => 'Total'], ['value' => '', 'num' => true], ['value' => $money($curTotal), 'num' => true]];
        }

        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('Purchases by Item'),
            'period' => "{$this->startDate} to {$this->endDate}".$this->comparisonNote(),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
            'emptyMessage' => 'No purchases in this period.',
        ], "purchases-by-item-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Purchases by Item'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.$this->comparisonNote()"
        mode="range"
        :comparison="true"
        :tracks-classes="$this->tracksClasses"
        :tracks-locations="$this->tracksLocations"
        :classification-options="$this->classificationOptions"
        :location-options="$this->locationOptions"
        :exports="['csv', 'xlsx', 'pdf']"
        :exports-disabled="$this->rows->isEmpty()"
        :title-editable="true"
    >
        @if ($this->columnRegistry())
            <x-reports.column-picker :columns="$this->columnRegistry()" />
        @endif
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Item') }}</th>
                    @if ($this->columnVisible('qty'))
                        <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    @endif
                    @if ($this->showComparison() && $this->columnVisible('prior_qty'))
                        <th class="px-4 py-2 text-right">{{ __('Prior Qty') }}</th>
                    @endif
                    @if ($this->showComparison() && $this->columnVisible('qty_change'))
                        <th class="px-4 py-2 text-right">{{ __('Qty Change') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Purchases') }}</th>
                    @if ($this->showComparison() && $this->columnVisible('prior'))
                        <th class="px-4 py-2 text-right">{{ __('Prior') }}</th>
                    @endif
                    @if ($this->showComparison() && $this->columnVisible('change'))
                        <th class="px-4 py-2 text-right">{{ __('Change') }}</th>
                    @endif
                    @if ($this->showComparison() && $this->columnVisible('change_pct'))
                        <th class="px-4 py-2 text-right">{{ __('% Change') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="purchases-row">
                        <td class="px-4 py-2">{{ $row->label }}</td>
                        @if ($this->columnVisible('qty'))
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($row->qty, 4), '0'), '.') }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('prior_qty'))
                            <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ rtrim(rtrim(number_format($row->priorQty, 4), '0'), '.') }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('qty_change'))
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($row->qtyChange(), 4), '0'), '.') }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row->amountCents / 100, 2) }}</td>
                        @if ($this->showComparison() && $this->columnVisible('prior'))
                            <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ number_format($row->priorAmountCents / 100, 2) }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change'))
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($row->changeCents() / 100, 2) }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change_pct'))
                            <td class="px-4 py-2 text-right font-mono text-muted-foreground">{{ $row->changePct() === null ? '—' : number_format($row->changePct(), 1).'%' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $this->visibleColumnCount(2) }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No purchases in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                @php($curTotal = (int) $this->rows->sum(fn ($r) => $r->amountCents))
                @php($priorTotal = (int) $this->rows->sum(fn ($r) => $r->priorAmountCents))
                @php($leadCols = 1 + ($this->columnVisible('qty') ? 1 : 0) + ($this->showComparison() && $this->columnVisible('prior_qty') ? 1 : 0) + ($this->showComparison() && $this->columnVisible('qty_change') ? 1 : 0))
                <tfoot class="bg-muted">
                    <tr>
                        <td class="px-4 py-3 font-semibold" colspan="{{ $leadCols }}">{{ __('Total') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="purchases-total">{{ number_format($curTotal / 100, 2) }}</td>
                        @if ($this->showComparison() && $this->columnVisible('prior'))
                            <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ number_format($priorTotal / 100, 2) }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change'))
                            <td class="px-4 py-3 text-right font-mono font-semibold">{{ number_format(($curTotal - $priorTotal) / 100, 2) }}</td>
                        @endif
                        @if ($this->showComparison() && $this->columnVisible('change_pct'))
                            <td class="px-4 py-3 text-right font-mono font-semibold text-muted-foreground">{{ $priorTotal !== 0 ? number_format(($curTotal - $priorTotal) / abs($priorTotal) * 100, 1).'%' : '—' }}</td>
                        @endif
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
