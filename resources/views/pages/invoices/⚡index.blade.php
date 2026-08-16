<?php

use App\Models\Company;
use App\Models\Invoice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invoices')] class extends Component {
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
    public function invoices()
    {
        return Invoice::query()
            ->with('contact')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('invoice_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>
            <flux:subheading>{{ __('Customer invoices.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="bell-alert" :href="route('reminders.index', ['company' => $company->slug])" wire:navigate data-test="reminders-link">
                {{ __('Reminders') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" :href="route('invoices.create', ['company' => $company->slug])" wire:navigate data-test="new-invoice-button">
                {{ __('New invoice') }}
            </flux:button>
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search invoice # or customer…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
            <flux:select.option value="posted">{{ __('Posted') }}</flux:select.option>
            <flux:select.option value="partial">{{ __('Partial') }}</flux:select.option>
            <flux:select.option value="paid">{{ __('Paid') }}</flux:select.option>
            <flux:select.option value="void">{{ __('Void') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->invoices as $inv)
            <a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $inv->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="invoice-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $inv->invoice_no }}</span>
                    @switch($inv->status->value)
                        @case('draft') <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge color="blue" size="sm">{{ __('Posted') }}</flux:badge> @break
                        @case('partial') <flux:badge color="indigo" size="sm">{{ __('Partial') }}</flux:badge> @break
                        @case('paid') <flux:badge color="green" size="sm">{{ __('Paid') }}</flux:badge> @break
                        @case('void') <flux:badge color="zinc" size="sm">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($inv->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $inv->invoice_date->toDateString() }} &middot; {{ __('Due') }} {{ $inv->due_date->toDateString() }}</div>
                    <div class="text-right">
                        <div class="font-mono font-semibold">{{ number_format($inv->total_cents / 100, 2) }}</div>
                        <div class="text-xs text-muted-foreground">{{ __('Bal') }} {{ number_format($inv->balanceCents() / 100, 2) }}</div>
                    </div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No invoices yet.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Invoice #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->invoices as $inv)
                    <tr data-test="invoice-row" class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $inv->invoice_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $inv->id]) }}" wire:navigate class="underline">{{ $inv->invoice_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($inv->contact)->display_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $inv->due_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($inv->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($inv->balanceCents() / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($inv->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                                @case('partial') <flux:badge color="indigo">{{ __('Partial') }}</flux:badge> @break
                                @case('paid') <flux:badge color="green">{{ __('Paid') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No invoices yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->invoices->links() }}</div>
</section>
