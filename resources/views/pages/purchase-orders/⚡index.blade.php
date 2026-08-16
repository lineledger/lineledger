<?php

use App\Models\Company;
use App\Models\PurchaseOrder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Orders')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function purchaseOrders()
    {
        return PurchaseOrder::query()
            ->with(['contact', 'lines.billLines.bill'])
            ->when($this->statusFilter !== 'all', fn ($q) => $this->applyStatusFilter($q, $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('po_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('po_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Filter by derived receipt status. Partial/Closed are not stored, so they are
     * expressed as EXISTS subqueries that mirror {@see PurchaseOrder::effectiveStatus()}:
     * Open = no non-void bill yet, Closed = billed with no line still backordered,
     * Partial = in between.
     */
    protected function applyStatusFilter($query, string $filter)
    {
        $hasBill = "EXISTS (SELECT 1 FROM bills b WHERE b.purchase_order_id = purchase_orders.id AND b.status <> 'void' AND b.deleted_at IS NULL)";
        $hasBackorder = "EXISTS (SELECT 1 FROM purchase_order_lines pol WHERE pol.purchase_order_id = purchase_orders.id AND pol.quantity > (SELECT COALESCE(SUM(bl.quantity), 0) FROM bill_lines bl JOIN bills b2 ON b2.id = bl.bill_id WHERE bl.purchase_order_line_id = pol.id AND b2.status <> 'void' AND b2.deleted_at IS NULL))";

        return match ($filter) {
            'cancelled' => $query->where('status', 'cancelled'),
            'open' => $query->where('status', '<>', 'cancelled')->whereRaw("NOT {$hasBill}"),
            'partial' => $query->where('status', '<>', 'cancelled')->whereRaw($hasBill)->whereRaw($hasBackorder),
            'closed' => $query->where('status', '<>', 'cancelled')->whereRaw($hasBill)->whereRaw("NOT {$hasBackorder}"),
            default => $query,
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Purchase Orders') }}</flux:heading>
            <flux:subheading>{{ __('Orders placed with vendors, received by billing over time.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('purchase-orders.create', ['company' => $company->slug])" wire:navigate data-test="new-purchase-order-button">
            {{ __('New purchase order') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search PO # or vendor…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="open">{{ __('Open') }}</flux:select.option>
            <flux:select.option value="partial">{{ __('Partial') }}</flux:select.option>
            <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
            <flux:select.option value="cancelled">{{ __('Cancelled') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->purchaseOrders as $order)
            <a href="{{ route('purchase-orders.show', ['company' => $company->slug, 'purchaseOrder' => $order->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="purchase-order-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $order->po_no }}</span>
                    @php($status = $order->effectiveStatus())
                    <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($order->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $order->po_date->toDateString() }}</div>
                    <div class="text-right">
                        <div class="font-mono font-semibold">{{ number_format($order->total_cents / 100, 2) }}</div>
                    </div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No purchase orders yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('PO #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Vendor') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Expected') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->purchaseOrders as $order)
                    <tr data-test="purchase-order-row" class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $order->po_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('purchase-orders.show', ['company' => $company->slug, 'purchaseOrder' => $order->id]) }}" wire:navigate class="underline">{{ $order->po_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($order->contact)->display_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ optional($order->expected_date)->toDateString() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($order->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @php($status = $order->effectiveStatus())
                            <flux:badge :color="$status->color()">{{ $status->label() }}</flux:badge>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No purchase orders yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->purchaseOrders->links() }}</div>
</section>
