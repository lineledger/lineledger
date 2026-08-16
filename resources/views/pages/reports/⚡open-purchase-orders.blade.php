<?php

use App\Concerns\HasCustomReportHeader;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Open Purchase Orders')] class extends Component {
    use HasCustomReportHeader;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, \App\Models\PurchaseOrder>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(SalesPurchaseReportBuilder::class)->openPurchaseOrders($this->company);
    }

    public function exportCsv()
    {
        $rows = $this->rows->map(fn ($po): array => [
            $po->po_no,
            $po->contact?->display_name ?? '',
            (string) $po->po_date?->toDateString(),
            (string) $po->expected_date?->toDateString(),
            $po->status->value,
            CsvExporter::cents((int) $po->total_cents),
        ]);

        return app(CsvExporter::class)->stream('open-purchase-orders.csv', ['PO #', 'Vendor', 'Date', 'Expected', 'Status', 'Total'], $rows);
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('Open Purchase Orders'))"
        :subtitle="__('Purchase orders not yet fully received.')"
        mode="none"
        :exports="['csv']"
        :exports-disabled="$this->rows->isEmpty()"
        :title-editable="true"
    />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('PO #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Vendor') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Expected') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $po)
                    <tr data-test="po-row">
                        <td class="px-4 py-2 font-mono">{{ $po->po_no }}</td>
                        <td class="px-4 py-2">{{ $po->contact?->display_name }}</td>
                        <td class="px-4 py-2">{{ $po->po_date?->toDateString() }}</td>
                        <td class="px-4 py-2">{{ $po->expected_date?->toDateString() }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" color="amber">{{ ucfirst($po->status->value) }}</flux:badge></td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($po->total_cents / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ __('No open purchase orders.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
