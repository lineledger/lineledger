<?php

use App\Concerns\HasCustomReportHeader;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\InventoryReportBuilder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inventory Valuation')] class extends Component {
    use HasCustomReportHeader;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>, total_value_cents: int}
     */
    #[Computed]
    public function report(): array
    {
        return app(InventoryReportBuilder::class)->valuationSummary($this->company);
    }

    public function exportCsv()
    {
        $rows = collect($this->report['rows'])->map(fn (array $r): array => [
            $r['sku'] ?? '',
            $r['name'],
            rtrim(rtrim(number_format($r['qty'], 4, '.', ''), '0'), '.'),
            CsvExporter::cents($r['avg_cost_cents']),
            CsvExporter::cents($r['value_cents']),
        ]);
        $rows->push(['', 'Total', '', '', CsvExporter::cents($this->report['total_value_cents'])]);

        return app(CsvExporter::class)->stream('inventory-valuation.csv', ['SKU', 'Item', 'Qty', 'Avg cost', 'Value'], $rows);
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Inventory Valuation'))"
        :subtitle="__('Current inventory value from remaining cost layers.')"
        mode="none"
        :exports="['csv']"
        :exports-disabled="$this->report['rows']->isEmpty()"
        :title-editable="true"
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('SKU') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Item') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Avg cost') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->report['rows'] as $row)
                    <tr data-test="valuation-row">
                        <td class="px-4 py-2 font-mono">{{ $row['sku'] }}</td>
                        <td class="px-4 py-2">{{ $row['name'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($row['qty'], 4), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['avg_cost_cents'] / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['value_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No inventory items.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($this->report['rows']->isNotEmpty())
                <tfoot class="bg-muted">
                    <tr>
                        <td class="px-4 py-3 font-semibold" colspan="4">{{ __('Total inventory value') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold" data-test="valuation-total">{{ number_format($this->report['total_value_cents'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
