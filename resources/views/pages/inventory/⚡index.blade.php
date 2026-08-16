<?php

use App\Models\Company;
use App\Models\Item;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Inventory')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'low')]
    public bool $lowStockOnly = false;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function items()
    {
        return Item::query()
            ->where('track_inventory', true)
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->lowStockOnly, function ($q) {
                $q->whereNotNull('reorder_point')
                    ->whereColumn('qty_on_hand_cached', '<', 'reorder_point');
            })
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function totalValueCents(): int
    {
        return (int) Item::query()
            ->where('track_inventory', true)
            ->get()
            ->sum(fn ($i) => (int) round((float) $i->qty_on_hand_cached * (int) $i->unit_cost_cents_cached));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Inventory') }}</flux:heading>
            <flux:text class="text-muted-foreground">
                {{ __('Stock on hand for tracked items.') }}
                <span class="ml-2 font-mono">{{ __('Total value') }}: {{ number_format($this->totalValueCents / 100, 2) }}</span>
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button href="{{ route('inventory.adjustments.index', $company) }}" icon="adjustments-horizontal">
                {{ __('Adjustments') }}
            </flux:button>
            <flux:button href="{{ route('lists.items', $company) }}" icon="plus" variant="primary">
                {{ __('Manage items') }}
            </flux:button>
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name or SKU') }}" class="sm:max-w-sm" />
        <flux:switch wire:model.live="lowStockOnly" :label="__('Low stock only')" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->items as $item)
            @php($qty = (float) $item->qty_on_hand_cached)
            @php($value = (int) round($qty * (int) $item->unit_cost_cents_cached))
            <a href="{{ route('inventory.item-history', [$company, $item]) }}" class="block rounded-lg border border-border p-4" data-test="inventory-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $item->name }}</span>
                    @if ($item->isBelowReorderPoint())
                        <flux:badge color="amber" size="sm">{{ __('Low') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-1 font-mono text-sm text-muted-foreground">{{ $item->sku ?: '—' }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ __('On hand') }}: <span class="font-mono">{{ rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') }}</span></div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($value / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No tracked items yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('SKU') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('On hand') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Avg unit cost') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Asset value') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Reorder at') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->items as $item)
                    @php($qty = (float) $item->qty_on_hand_cached)
                    @php($value = (int) round($qty * (int) $item->unit_cost_cents_cached))
                    <tr>
                        <td class="px-4 py-2">
                            <a href="{{ route('inventory.item-history', [$company, $item]) }}" class="text-foreground hover:underline">
                                {{ $item->name }}
                            </a>
                            @if ($item->isBelowReorderPoint())
                                <flux:badge color="amber" size="sm" class="ml-2">{{ __('Low') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground font-mono">{{ $item->sku ?: '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($item->unit_cost_cents_cached / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($value / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $item->reorder_point !== null ? rtrim(rtrim(number_format((float) $item->reorder_point, 4, '.', ''), '0'), '.') : '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="arrow-right" href="{{ route('inventory.item-history', [$company, $item]) }}" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No tracked items yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->items->links() }}</div>
</section>
