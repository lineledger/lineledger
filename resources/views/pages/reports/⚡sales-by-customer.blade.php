<?php

use App\Concerns\HasColumnToggles;
use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportComparison;
use App\Concerns\HasReportDateRange;
use App\Concerns\HasReportDimensions;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use App\Services\Reporting\XlsxExporter;
use App\Support\Reporting\ComparisonRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales by Customer')] class extends Component {
    use HasColumnToggles;
    use HasCustomReportHeader;
    use HasReportComparison;
    use HasReportDateRange;
    use HasReportDimensions;

    public Company $company;

    #[Url(as: 'contact')]
    public ?int $contactId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $this->initReportDateRange();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contactOptions(): Collection
    {
        return Contact::query()->where('is_customer', true)->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function contactFilter(): ?Contact
    {
        return $this->contactId ? Contact::find($this->contactId) : null;
    }

    /** @return array<string, string> */
    public function columnRegistry(): array
    {
        if (! $this->showComparison()) {
            return [];
        }

        return [
            'prior' => __('Prior'),
            'change' => __('Change'),
            'change_pct' => __('% Change'),
        ];
    }

    /**
     * @return Collection<int, ComparisonRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        $builder = app(SalesPurchaseReportBuilder::class);

        $current = $builder->salesByDimension(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            'contact',
            $this->effectiveClassId(),
            $this->effectiveLocationId(),
            $this->contactId ?: null,
        );

        $prior = collect();

        if ($this->showComparison()) {
            [$priorStart, $priorEnd] = $this->comparisonRange();

            $prior = $builder->salesByDimension(
                $this->company,
                $priorStart,
                $priorEnd,
                'contact',
                $this->effectiveClassId(),
                $this->effectiveLocationId(),
                $this->contactId ?: null,
            );
        }

        return $builder->mergeComparison($current, $prior);
    }

    public function exportCsv()
    {
        if ($this->showComparison()) {
            $headers = ['Customer', 'Sales', 'Prior', 'Change', '% Change'];
            $pct = fn (?float $p): string => $p === null ? '' : number_format($p, 1).'%';
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, CsvExporter::cents($r->amountCents), CsvExporter::cents($r->priorAmountCents), CsvExporter::cents($r->changeCents()), $pct($r->changePct())]);
            $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $rows->push(['Total', CsvExporter::cents($curTotal), CsvExporter::cents($priorTotal), CsvExporter::cents($curTotal - $priorTotal), '']);
        } else {
            $headers = ['Customer', 'Sales'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, CsvExporter::cents($r->amountCents)]);
            $rows->push(['Total', CsvExporter::cents((int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents))]);
        }

        return app(CsvExporter::class)->stream("sales-by-customer-{$this->startDate}-{$this->endDate}.csv", $headers, $rows);
    }

    public function exportXlsx()
    {
        $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);

        if ($this->showComparison()) {
            $pct = fn (?float $p): string => $p === null ? '' : number_format($p, 1).'%';
            $headers = ['Customer', 'Sales', 'Prior', 'Change', '% Change'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $r->amountCents, $r->priorAmountCents, $r->changeCents(), $pct($r->changePct())])->all();
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $totals = ['Total', $curTotal, $priorTotal, $curTotal - $priorTotal, ''];
            $moneyColumns = [2, 3, 4];
            $columnWidths = [1 => 32, 2 => 16, 3 => 16, 4 => 16, 5 => 12];
        } else {
            $headers = ['Customer', 'Sales'];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [$r->label, $r->amountCents])->all();
            $totals = ['Total', $curTotal];
            $moneyColumns = [2];
            $columnWidths = [1 => 32, 2 => 16];
        }

        return app(XlsxExporter::class)->listTable(
            "sales-by-customer-{$this->startDate}-{$this->endDate}.xlsx",
            'Sales by Customer',
            $this->effectiveTitle('Sales by Customer'),
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
        $money = fn (int $c): string => number_format($c / 100, 2);
        $curTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->amountCents);

        if ($this->showComparison()) {
            $pct = fn (?float $p): string => $p === null ? '—' : number_format($p, 1).'%';
            $priorTotal = (int) $this->rows->sum(fn (ComparisonRow $r): int => $r->priorAmountCents);
            $headers = [['label' => 'Customer'], ['label' => 'Sales', 'num' => true], ['label' => 'Prior', 'num' => true], ['label' => 'Change', 'num' => true], ['label' => '% Change', 'num' => true]];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [
                ['value' => $r->label],
                ['value' => $money($r->amountCents), 'num' => true],
                ['value' => $money($r->priorAmountCents), 'num' => true],
                ['value' => $money($r->changeCents()), 'num' => true],
                ['value' => $pct($r->changePct()), 'num' => true],
            ])->all();
            $totals = [['value' => 'Total'], ['value' => $money($curTotal), 'num' => true], ['value' => $money($priorTotal), 'num' => true], ['value' => $money($curTotal - $priorTotal), 'num' => true], ['value' => '', 'num' => true]];
        } else {
            $headers = [['label' => 'Customer'], ['label' => 'Sales', 'num' => true]];
            $rows = $this->rows->map(fn (ComparisonRow $r): array => [['value' => $r->label], ['value' => $money($r->amountCents), 'num' => true]])->all();
            $totals = [['value' => 'Total'], ['value' => $money($curTotal), 'num' => true]];
        }

        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => $this->effectiveTitle('Sales by Customer'),
            'period' => "{$this->startDate} to {$this->endDate}".$this->comparisonNote(),
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
            'emptyMessage' => 'No sales in this period.',
        ], "sales-by-customer-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Sales by Customer'))"
        :subtitle="$company->name.' · '.$startDate.' '.__('to').' '.$endDate.($this->contactFilter ? ' · '.$this->contactFilter->display_name : '').$this->comparisonNote()"
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
        <flux:select wire:model.live="contactId" :label="__('Customer')" class="max-w-[180px]" data-test="filter-contact">
            <flux:select.option value="">{{ __('All customers') }}</flux:select.option>
            @foreach ($this->contactOptions as $opt)
                <flux:select.option :value="$opt->id">{{ $opt->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->columnRegistry())
            <x-reports.column-picker :columns="$this->columnRegistry()" />
        @endif
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Sales') }}</th>
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
                    <tr data-test="sales-row">
                        <td class="px-4 py-2">
                            @if ($row->key)
                                <a href="{{ route('reports.transactions', ['company' => $company->slug, 'contact' => $row->key, 'start' => $startDate, 'end' => $endDate]) }}" wire:navigate class="hover:underline" data-test="drill-contact">{{ $row->label }}</a>
                            @else
                                {{ $row->label }}
                            @endif
                        </td>
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
                    <tr><td colspan="{{ $this->visibleColumnCount(2) }}" class="px-4 py-6 text-center text-muted-foreground">{{ __('No sales in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->rows->isNotEmpty())
                @php($curTotal = (int) $this->rows->sum(fn ($r) => $r->amountCents))
                @php($priorTotal = (int) $this->rows->sum(fn ($r) => $r->priorAmountCents))
                <tfoot class="bg-muted">
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ __('Total') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="sales-total">{{ number_format($curTotal / 100, 2) }}</td>
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
