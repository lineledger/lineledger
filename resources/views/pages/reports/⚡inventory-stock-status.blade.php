<?php

use App\Concerns\HasCustomReportHeader;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\InventoryReportBuilder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inventory Stock Status')] class extends Component {
    use HasCustomReportHeader;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, array{item_id: int, name: string, sku: ?string, qty_on_hand: float, reorder_point: ?float, unit_cost_cents: int, below_reorder: bool}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(InventoryReportBuilder::class)->stockStatus($this->company);
    }

    public function exportCsv()
    {
        $rows = $this->rows->map(fn (array $r): array => [
            $r['sku'] ?? '',
            $r['name'],
            rtrim(rtrim(number_format($r['qty_on_hand'], 4, '.', ''), '0'), '.'),
            $r['reorder_point'] !== null ? rtrim(rtrim(number_format($r['reorder_point'], 4, '.', ''), '0'), '.') : '',
            CsvExporter::cents($r['unit_cost_cents']),
        ]);

        return app(CsvExporter::class)->stream('inventory-stock-status.csv', ['SKU', 'Item', 'On hand', 'Reorder point', 'Unit cost'], $rows);
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Inventory Stock Status'))"
        :subtitle="__('On-hand quantities for inventory items.')"
        mode="none"
        :exports="['csv']"
        :exports-disabled="$this->rows->isEmpty()"
        :title-editable="true"
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('SKU') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Item') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('On hand') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Reorder point') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit cost') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="stock-row" @class(['bg-red-50 dark:bg-red-950/20' => $row['below_reorder']])>
                        <td class="px-4 py-2 font-mono">{{ $row['sku'] }}</td>
                        <td class="px-4 py-2">
                            {{ $row['name'] }}
                            @if ($row['below_reorder'])
                                <flux:badge size="sm" color="red">{{ __('Reorder') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($row['qty_on_hand'], 4), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $row['reorder_point'] !== null ? rtrim(rtrim(number_format($row['reorder_point'], 4), '0'), '.') : '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['unit_cost_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No inventory items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
