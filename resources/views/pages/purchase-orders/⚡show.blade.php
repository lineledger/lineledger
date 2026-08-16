<?php

use App\Actions\Purchasing\FulfillPurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\Company;
use App\Models\PurchaseOrder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Order')] class extends Component {
    public Company $company;

    public PurchaseOrder $purchaseOrder;

    public bool $receiving = false;

    /**
     * Quantities to bill, keyed by purchase_order_line_id.
     *
     * @var array<int, string>
     */
    public array $receiveQty = [];

    public function mount(Company $company, PurchaseOrder $purchaseOrder): void
    {
        $this->company = $company;
        $this->loadOrder();
    }

    protected function loadOrder(): void
    {
        $this->purchaseOrder = $this->purchaseOrder
            ->load('lines.account', 'lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.billLines.bill', 'contact', 'bills');
    }

    #[Computed]
    public function status(): PurchaseOrderStatus
    {
        return $this->purchaseOrder->effectiveStatus();
    }

    /**
     * Open the receiving panel, prefilling each line with its outstanding qty.
     */
    public function startReceive(): void
    {
        $this->receiveQty = [];

        foreach ($this->purchaseOrder->lines as $line) {
            $backordered = $line->qtyBackordered();
            if ($backordered > 0.00001) {
                $this->receiveQty[$line->id] = rtrim(rtrim(number_format($backordered, 4, '.', ''), '0'), '.');
            }
        }

        $this->receiving = true;
    }

    public function cancelReceive(): void
    {
        $this->receiving = false;
        $this->receiveQty = [];
    }

    public function receive(FulfillPurchaseOrder $action): void
    {
        $quantities = array_filter(
            array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, $this->receiveQty),
            fn ($v) => $v > 0,
        );

        try {
            $bill = $action->handle($this->purchaseOrder, $quantities);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft bill created from purchase order.'));
        $this->redirectRoute('bills.edit', ['company' => $this->company->slug, 'bill' => $bill->id], navigate: true);
    }

    public function cancelOrder(): void
    {
        $status = $this->purchaseOrder->effectiveStatus();

        if (! in_array($status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial], true)) {
            return;
        }

        $this->purchaseOrder->update(['status' => PurchaseOrderStatus::Cancelled]);
        $this->loadOrder();

        Flux::toast(variant: 'success', text: __('Purchase order cancelled.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Purchase Order') }} {{ $purchaseOrder->po_no }}</flux:heading>
            <flux:subheading>
                {{ $purchaseOrder->contact->display_name }} &middot;
                {{ $purchaseOrder->po_date->toDateString() }}
                @if ($purchaseOrder->expected_date)
                    &middot; {{ __('Expected') }} {{ $purchaseOrder->expected_date->toDateString() }}
                @endif
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                <flux:badge :color="$this->status->color()">{{ $this->status->label() }}</flux:badge>
            </div>

            @if ($purchaseOrder->ship_to)
                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground" data-test="purchase-order-meta">
                    <div>{{ __('Ship to') }}: {{ $purchaseOrder->ship_to }}</div>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial], true))
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="document-plus" wire:click="startReceive" data-test="create-bill-button">
                    {{ __('Create bill') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="purchase-order-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if (in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial], true))
                        <flux:menu.item class="lg:hidden" icon="document-plus" wire:click="startReceive" data-test="create-bill-menu-item">
                            {{ __('Create bill') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('purchase-orders.print', ['company' => $company->slug, 'purchaseOrder' => $purchaseOrder->id])" target="_blank" data-test="print-purchase-order-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($this->status->isEditable())
                        <flux:menu.item icon="pencil" :href="route('purchase-orders.edit', ['company' => $company->slug, 'purchaseOrder' => $purchaseOrder->id])" wire:navigate data-test="edit-purchase-order-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if (in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::Partial], true))
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" variant="danger" wire:click="cancelOrder" wire:confirm="{{ __('Cancel this purchase order? No further billing will be allowed.') }}" data-test="cancel-purchase-order-button">
                            {{ __('Cancel order') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($receiving)
        <div class="mb-6 rounded-lg border border-border p-4" data-test="receiving-panel">
            <flux:heading size="lg" class="mb-3">{{ __('Create bill for these quantities') }}</flux:heading>
            <flux:text class="mb-4 text-muted-foreground">{{ __('Each quantity is prefilled with the amount still outstanding. Adjust as needed; a draft bill will be created for what you enter.') }}</flux:text>

            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Ordered') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Billed') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Backordered') }}</th>
                        <th class="px-4 py-2 text-right w-32">{{ __('Bill now') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($purchaseOrder->lines as $line)
                        @php
                            $backordered = $line->qtyBackordered();
                        @endphp
                        <tr wire:key="receive-{{ $line->id }}">
                            <td class="px-4 py-2">{{ $line->description }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($line->qtyBilled(), 4, '.', ''), '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($backordered, 4, '.', ''), '0'), '.') }}</td>
                            <td class="px-4 py-2">
                                @if ($backordered > 0.00001)
                                    <flux:input wire:model="receiveQty.{{ $line->id }}" class="text-right" data-test="receive-qty-{{ $line->id }}" />
                                @else
                                    <div class="text-right text-muted-foreground">—</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="cancelReceive">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="receive" data-test="confirm-receive-button">{{ __('Create draft bill') }}</flux:button>
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
                    <th class="px-4 py-2 text-right">{{ __('Billed') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Backordered') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($purchaseOrder->lines as $line)
                    <tr data-test="purchase-order-line">
                        <td class="px-4 py-2">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($line->qtyBilled(), 4, '.', ''), '0'), '.') }}</td>
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
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($purchaseOrder->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($purchaseOrder->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="purchase-order-tax-row">
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($purchaseOrder->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($purchaseOrder->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($purchaseOrder->bills->isNotEmpty())
        <div class="mt-6">
            <flux:heading size="lg" class="mb-3">{{ __('Bills') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Bill #') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-2">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($purchaseOrder->bills as $bill)
                            <tr data-test="linked-bill-row">
                                <td class="px-4 py-2 font-mono"><a href="{{ route('bills.show', ['company' => $company->slug, 'bill' => $bill->id]) }}" wire:navigate class="underline">{{ $bill->bill_no }}</a></td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $bill->bill_date->toDateString() }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($bill->total_cents / 100, 2) }}</td>
                                <td class="px-4 py-2"><flux:badge>{{ $bill->status->label() }}</flux:badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($purchaseOrder->vendor_message)
        <flux:text class="mt-4 text-muted-foreground">{{ $purchaseOrder->vendor_message }}</flux:text>
    @endif

    @if ($purchaseOrder->memo)
        <flux:text class="mt-2 text-muted-foreground">{{ $purchaseOrder->memo }}</flux:text>
    @endif
</section>
