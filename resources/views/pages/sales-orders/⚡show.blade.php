<?php

use App\Actions\Sales\FulfillSalesOrder;
use App\Enums\SalesOrderStatus;
use App\Models\Company;
use App\Models\SalesOrder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Order')] class extends Component {
    public Company $company;

    public SalesOrder $salesOrder;

    public bool $fulfilling = false;

    /**
     * Quantities to invoice, keyed by sales_order_line_id.
     *
     * @var array<int, string>
     */
    public array $fulfillQty = [];

    public function mount(Company $company, SalesOrder $salesOrder): void
    {
        $this->company = $company;
        $this->loadOrder();
    }

    protected function loadOrder(): void
    {
        $this->salesOrder = $this->salesOrder
            ->load('lines.account', 'lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.invoiceLines.invoice', 'contact', 'salesRep', 'invoices');
    }

    #[Computed]
    public function status(): SalesOrderStatus
    {
        return $this->salesOrder->effectiveStatus();
    }

    /**
     * Open the fulfillment panel, prefilling each line with its outstanding qty.
     */
    public function startFulfill(): void
    {
        $this->fulfillQty = [];

        foreach ($this->salesOrder->lines as $line) {
            $backordered = $line->qtyBackordered();
            if ($backordered > 0.00001) {
                $this->fulfillQty[$line->id] = rtrim(rtrim(number_format($backordered, 4, '.', ''), '0'), '.');
            }
        }

        $this->fulfilling = true;
    }

    public function cancelFulfill(): void
    {
        $this->fulfilling = false;
        $this->fulfillQty = [];
    }

    public function fulfill(FulfillSalesOrder $action): void
    {
        $quantities = array_filter(
            array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, $this->fulfillQty),
            fn ($v) => $v > 0,
        );

        try {
            $invoice = $action->handle($this->salesOrder, $quantities);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft invoice created from sales order.'));
        $this->redirectRoute('invoices.edit', ['company' => $this->company->slug, 'invoice' => $invoice->id], navigate: true);
    }

    public function cancelOrder(): void
    {
        $status = $this->salesOrder->effectiveStatus();

        if (! in_array($status, [SalesOrderStatus::Open, SalesOrderStatus::Partial], true)) {
            return;
        }

        $this->salesOrder->update(['status' => SalesOrderStatus::Cancelled]);
        $this->loadOrder();

        Flux::toast(variant: 'success', text: __('Sales order cancelled.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sales Order') }} {{ $salesOrder->order_no }}</flux:heading>
            <flux:subheading>
                {{ $salesOrder->contact->display_name }} &middot;
                {{ $salesOrder->order_date->toDateString() }}
                @if ($salesOrder->expected_date)
                    &middot; {{ __('Expected') }} {{ $salesOrder->expected_date->toDateString() }}
                @endif
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                <flux:badge :color="$this->status->color()">{{ $this->status->label() }}</flux:badge>
            </div>

            @if ($salesOrder->salesRep || $salesOrder->customer_po || $salesOrder->ship_date || $salesOrder->ship_via || $salesOrder->fob || $salesOrder->tracking_no)
                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground" data-test="sales-order-meta">
                    @if ($salesOrder->salesRep)<div>{{ __('Sales rep') }}: {{ $salesOrder->salesRep->display_name }}</div>@endif
                    @if ($salesOrder->customer_po)<div>{{ __('PO #') }}: {{ $salesOrder->customer_po }}</div>@endif
                    @if ($salesOrder->ship_date)<div>{{ __('Ship date') }}: {{ $salesOrder->ship_date->toDateString() }}</div>@endif
                    @if ($salesOrder->ship_via)<div>{{ __('Ship via') }}: {{ $salesOrder->ship_via }}</div>@endif
                    @if ($salesOrder->fob)<div>{{ __('FOB') }}: {{ $salesOrder->fob }}</div>@endif
                    @if ($salesOrder->tracking_no)<div>{{ __('Tracking #') }}: {{ $salesOrder->tracking_no }}</div>@endif
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::Partial], true))
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="document-plus" wire:click="startFulfill" data-test="create-invoice-button">
                    {{ __('Create invoice') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="sales-order-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if (in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::Partial], true))
                        <flux:menu.item class="lg:hidden" icon="document-plus" wire:click="startFulfill" data-test="create-invoice-menu-item">
                            {{ __('Create invoice') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('sales-orders.print', ['company' => $company->slug, 'salesOrder' => $salesOrder->id])" target="_blank" data-test="print-sales-order-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($this->status->isEditable())
                        <flux:menu.item icon="pencil" :href="route('sales-orders.edit', ['company' => $company->slug, 'salesOrder' => $salesOrder->id])" wire:navigate data-test="edit-sales-order-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if (in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::Partial], true))
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" variant="danger" wire:click="cancelOrder" wire:confirm="{{ __('Cancel this sales order? No further invoicing will be allowed.') }}" data-test="cancel-sales-order-button">
                            {{ __('Cancel order') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($fulfilling)
        <div class="mb-6 rounded-lg border border-border p-4" data-test="fulfillment-panel">
            <flux:heading size="lg" class="mb-3">{{ __('Create invoice for these quantities') }}</flux:heading>
            <flux:text class="mb-4 text-muted-foreground">{{ __('Each quantity is prefilled with the amount still outstanding. Adjust as needed; a draft invoice will be created for what you enter.') }}</flux:text>

            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Ordered') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Invoiced') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Backordered') }}</th>
                        <th class="px-4 py-2 text-right w-32">{{ __('Invoice now') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($salesOrder->lines as $line)
                        @php
                            $backordered = $line->qtyBackordered();
                        @endphp
                        <tr wire:key="fulfill-{{ $line->id }}">
                            <td class="px-4 py-2">{{ $line->description }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($line->qtyInvoiced(), 4, '.', ''), '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($backordered, 4, '.', ''), '0'), '.') }}</td>
                            <td class="px-4 py-2">
                                @if ($backordered > 0.00001)
                                    <flux:input wire:model="fulfillQty.{{ $line->id }}" class="text-right" data-test="fulfill-qty-{{ $line->id }}" />
                                @else
                                    <div class="text-right text-muted-foreground">—</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="cancelFulfill">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="fulfill" data-test="confirm-fulfill-button">{{ __('Create draft invoice') }}</flux:button>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Ordered') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Invoiced') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Backordered') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($salesOrder->lines as $line)
                    <tr data-test="sales-order-line">
                        <td class="px-4 py-2">
                            {{ $line->description }}
                            @if ($line->service_date)
                                <div class="text-xs text-muted-foreground">{{ __('Service date') }}: {{ $line->service_date->toDateString() }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($line->qtyInvoiced(), 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="line-backordered">{{ rtrim(rtrim(number_format($line->qtyBackordered(), 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->unit_price_cents / 100, 2) }}
                            @if ($line->line_discount_cents)
                                <div class="text-xs text-muted-foreground">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ optional($line->taxCode)->code }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($salesOrder->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($salesOrder->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="sales-order-tax-row">
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($salesOrder->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($salesOrder->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($salesOrder->invoices->isNotEmpty())
        <div class="mt-6">
            <flux:heading size="lg" class="mb-3">{{ __('Invoices') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Invoice #') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-2">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($salesOrder->invoices as $invoice)
                            <tr data-test="linked-invoice-row">
                                <td class="px-4 py-2 font-mono"><a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $invoice->id]) }}" wire:navigate class="underline">{{ $invoice->invoice_no }}</a></td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $invoice->invoice_date->toDateString() }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->total_cents / 100, 2) }}</td>
                                <td class="px-4 py-2"><flux:badge>{{ $invoice->status->label() }}</flux:badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($salesOrder->customer_message)
        <flux:text class="mt-4 text-muted-foreground">{{ $salesOrder->customer_message }}</flux:text>
    @endif

    @if ($salesOrder->memo)
        <flux:text class="mt-2 text-muted-foreground">{{ $salesOrder->memo }}</flux:text>
    @endif
</section>
