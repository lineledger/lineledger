<?php

use App\Models\Company;
use App\Models\Estimate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Estimates')] class extends Component {
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
    public function estimates()
    {
        $today = $this->company->currentDateTime()->startOfDay()->toDateString();

        return Estimate::query()
            ->with('contact')
            // "Expired" is derived from a still-Pending quote past its expiry date.
            ->when($this->statusFilter === 'expired', fn ($q) => $q
                ->where('status', 'pending')
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $today))
            ->when(! in_array($this->statusFilter, ['all', 'expired'], true), fn ($q) => $q
                ->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('estimate_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('estimate_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Estimates') }}</flux:heading>
            <flux:subheading>{{ __('Customer quotes and estimates.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('estimates.create', ['company' => $company->slug])" wire:navigate data-test="new-estimate-button">
            {{ __('New estimate') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search estimate # or customer…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="pending">{{ __('Pending') }}</flux:select.option>
            <flux:select.option value="accepted">{{ __('Accepted') }}</flux:select.option>
            <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
            <flux:select.option value="converted">{{ __('Converted') }}</flux:select.option>
            <flux:select.option value="expired">{{ __('Expired') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->estimates as $est)
            <a href="{{ route('estimates.show', ['company' => $company->slug, 'estimate' => $est->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="estimate-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $est->estimate_no }}</span>
                    @switch($est->effectiveStatus()->value)
                        @case('pending') <flux:badge color="amber" size="sm">{{ __('Pending') }}</flux:badge> @break
                        @case('accepted') <flux:badge color="blue" size="sm">{{ __('Accepted') }}</flux:badge> @break
                        @case('rejected') <flux:badge color="red" size="sm">{{ __('Rejected') }}</flux:badge> @break
                        @case('converted') <flux:badge color="green" size="sm">{{ __('Converted') }}</flux:badge> @break
                        @case('expired') <flux:badge color="zinc" size="sm">{{ __('Expired') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($est->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $est->estimate_date->toDateString() }} &middot; {{ __('Expires') }} {{ optional($est->expires_on)->toDateString() ?? '—' }}</div>
                    <div class="text-right">
                        <div class="font-mono font-semibold">{{ number_format($est->total_cents / 100, 2) }}</div>
                    </div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No estimates yet.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Estimate #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Expires') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->estimates as $est)
                    <tr data-test="estimate-row" class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $est->estimate_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('estimates.show', ['company' => $company->slug, 'estimate' => $est->id]) }}" wire:navigate class="underline">{{ $est->estimate_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($est->contact)->display_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ optional($est->expires_on)->toDateString() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($est->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($est->effectiveStatus()->value)
                                @case('pending') <flux:badge color="amber">{{ __('Pending') }}</flux:badge> @break
                                @case('accepted') <flux:badge color="blue">{{ __('Accepted') }}</flux:badge> @break
                                @case('rejected') <flux:badge color="red">{{ __('Rejected') }}</flux:badge> @break
                                @case('converted') <flux:badge color="green">{{ __('Converted') }}</flux:badge> @break
                                @case('expired') <flux:badge color="zinc">{{ __('Expired') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No estimates yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->estimates->links() }}</div>
</section>
