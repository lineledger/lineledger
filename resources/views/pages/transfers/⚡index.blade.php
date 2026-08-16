<?php

use App\Models\Company;
use App\Models\Transfer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transfers')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function transfers()
    {
        return Transfer::query()
            ->with('fromAccount', 'toAccount')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('transfer_no', 'like', '%'.$this->search.'%')
                ->orWhere('memo', 'like', '%'.$this->search.'%')))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Transfers') }}</flux:heading>
            <flux:subheading>{{ __('Move money between bank and cash accounts.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('transfers.create', ['company' => $company->slug])" wire:navigate data-test="new-transfer-button">
            {{ __('New transfer') }}
        </flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search transfer #…') }}" icon="magnifying-glass" class="mb-4 max-w-md" />

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->transfers as $t)
            <a href="{{ route('transfers.show', ['company' => $company->slug, 'transfer' => $t->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="transfer-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $t->transfer_no }}</span>
                    @switch($t->status->value)
                        @case('draft') <flux:badge size="sm" color="amber">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge size="sm" color="green">{{ __('Posted') }}</flux:badge> @break
                        @case('void') <flux:badge size="sm" color="zinc">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($t->fromAccount)->name }} → {{ optional($t->toAccount)->name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $t->transfer_date->toDateString() }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($t->from_amount_cents / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No transfers yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Transfer #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('From') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('To') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->transfers as $t)
                    <tr data-test="transfer-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $t->transfer_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('transfers.show', ['company' => $company->slug, 'transfer' => $t->id]) }}" wire:navigate class="underline">{{ $t->transfer_no }}</a></td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($t->fromAccount)->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($t->toAccount)->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($t->from_amount_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($t->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No transfers yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->transfers->links() }}</div>
</section>
