<?php

use App\Models\Company;
use App\Models\VendorCredit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Vendor credits')] class extends Component {
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
    public function vendorCredits()
    {
        return VendorCredit::query()
            ->with('contact')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('vendor_credit_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('vendor_credit_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Vendor credits') }}</flux:heading>
            <flux:subheading>{{ __('Credits from vendors, reducing what you owe.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('vendor-credits.create', ['company' => $company->slug])" wire:navigate data-test="new-vendor-credit-button">
            {{ __('New vendor credit') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search credit # or vendor…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="statusFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
            <flux:select.option value="posted">{{ __('Posted') }}</flux:select.option>
            <flux:select.option value="void">{{ __('Void') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->vendorCredits as $credit)
            <a href="{{ route('vendor-credits.show', ['company' => $company->slug, 'vendor_credit' => $credit->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="vendor-credit-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $credit->vendor_credit_no }}</span>
                    @switch($credit->status->value)
                        @case('draft') <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge color="blue" size="sm">{{ __('Posted') }}</flux:badge> @break
                        @case('void') <flux:badge color="zinc" size="sm">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($credit->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $credit->vendor_credit_date->toDateString() }}</div>
                    <div class="text-right">
                        <div class="font-mono font-semibold">{{ number_format($credit->total_cents / 100, 2) }}</div>
                    </div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No vendor credits yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Credit #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Vendor') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->vendorCredits as $credit)
                    <tr data-test="vendor-credit-row" class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $credit->vendor_credit_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('vendor-credits.show', ['company' => $company->slug, 'vendor_credit' => $credit->id]) }}" wire:navigate class="underline">{{ $credit->vendor_credit_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($credit->contact)->display_name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($credit->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($credit->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No vendor credits yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->vendorCredits->links() }}</div>
</section>
