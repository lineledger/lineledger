<?php

use App\Models\Company;
use App\Models\TaxReturn;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tax returns')] class extends Component {
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
    public function taxReturns()
    {
        return TaxReturn::query()
            ->with('taxAgency')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('tax_return_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('taxAgency', fn ($a) => $a->where('name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Tax returns') }}</flux:heading>
            <flux:subheading>{{ __('Filed :label and other tax returns. Each filing is a frozen audit-ready snapshot of the transactions that contributed to the return.', ['label' => $company->jurisdiction->taxLabel()]) }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('tax-returns.create', ['company' => $company->slug])" wire:navigate data-test="new-tax-return-button">
            {{ __('File new return') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search return # or agency…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
            <flux:select.option value="filed">{{ __('Filed') }}</flux:select.option>
            <flux:select.option value="void">{{ __('Void') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->taxReturns as $return)
            <a href="{{ route('tax-returns.show', ['company' => $company->slug, 'tax_return' => $return->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="tax-return-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $return->tax_return_no }}</span>
                    @switch($return->status->value)
                        @case('draft') <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge> @break
                        @case('filed') <flux:badge color="blue" size="sm">{{ __('Filed') }}</flux:badge> @break
                        @case('void') <flux:badge color="zinc" size="sm">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($return->taxAgency)->name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $return->period_start->toDateString() }} → {{ $return->period_end->toDateString() }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($return->net_cents / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No tax returns yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Period end') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Return #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Agency') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Period') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Collected') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Net') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->taxReturns as $return)
                    <tr data-test="tax-return-row" class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $return->period_end->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ route('tax-returns.show', ['company' => $company->slug, 'tax_return' => $return->id]) }}" wire:navigate class="underline">{{ $return->tax_return_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ optional($return->taxAgency)->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $return->period_start->toDateString() }} → {{ $return->period_end->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($return->collected_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($return->paid_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($return->net_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($return->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('filed') <flux:badge color="blue">{{ __('Filed') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No tax returns yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->taxReturns->links() }}</div>
</section>
