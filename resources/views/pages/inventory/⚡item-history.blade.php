<?php

use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Item history')] class extends Component {
    use WithPagination;
    public Company $company;

    public Item $item;

    public function mount(Company $company, Item $item): void
    {
        $this->company = $company;
        $this->item = $item;
    }

    #[Computed]
    public function movements()
    {
        return StockMovement::query()
            ->where('item_id', $this->item->id)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(50);
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:button href="{{ route('inventory.index', $company) }}" variant="ghost" icon="arrow-left" size="sm">
            {{ __('Back to inventory') }}
        </flux:button>
    </div>

    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $item->name }}</flux:heading>
            <flux:text class="text-muted-foreground">
                {{ $item->sku ?: '—' }}
                @if (! $item->track_inventory)
                    <flux:badge color="zinc" size="sm" class="ml-2">{{ __('Not tracked') }}</flux:badge>
                @endif
            </flux:text>
        </div>
        <div class="text-right">
            <flux:heading size="lg" class="font-mono">{{ rtrim(rtrim(number_format((float) $item->qty_on_hand_cached, 4, '.', ''), '0'), '.') }}</flux:heading>
            <flux:text class="text-muted-foreground">{{ __('on hand @') }} {{ number_format($item->unit_cost_cents_cached / 100, 2) }}/unit</flux:text>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Source') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Unit cost') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Value') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->movements as $m)
                    <tr @if ($m->reversal_of_movement_id) class="opacity-60" @endif>
                        <td class="px-4 py-2">{{ $m->movement_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            @if ($m->source_type)
                                {{ class_basename($m->source_type) }} #{{ $m->source_id }}
                            @else
                                {{ __('Manual') }}
                            @endif
                            @if ($m->reversal_of_movement_id)
                                <flux:badge color="zinc" size="sm">{{ __('Reversal') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono @if ((float) $m->qty_change > 0) text-emerald-600 dark:text-emerald-400 @else text-red-600 dark:text-red-400 @endif">
                            {{ rtrim(rtrim(number_format((float) $m->qty_change, 4, '.', ''), '0'), '.') }}
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($m->unit_cost_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($m->total_cost_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $m->notes }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No movements yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->movements->links() }}</div>
</section>
