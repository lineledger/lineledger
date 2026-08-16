<?php

use App\Models\Bill;
use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Reimbursements')] class extends Component {
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
    public function reimbursements()
    {
        return Bill::query()
            ->reimbursement()
            ->with('contact')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('bill_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Employee Reimbursements') }}</flux:heading>
            <flux:subheading>{{ __('Expenses paid by employees that you owe back.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('reimbursements.create', ['company' => $company->slug])" wire:navigate data-test="new-reimbursement-button">
            {{ __('New reimbursement') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search reimbursement # or employee…') }}" icon="magnifying-glass" class="sm:max-w-md" />
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
        @forelse ($this->reimbursements as $r)
            <a href="{{ route('reimbursements.show', ['company' => $company->slug, 'bill' => $r->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="reimbursement-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $r->bill_no }}</span>
                    @switch($r->status->value)
                        @case('draft') <flux:badge size="sm" color="amber">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge size="sm" color="blue">{{ __('Posted') }}</flux:badge> @break
                        @case('partial') <flux:badge size="sm" color="indigo">{{ __('Partial') }}</flux:badge> @break
                        @case('paid') <flux:badge size="sm" color="green">{{ __('Paid') }}</flux:badge> @break
                        @case('void') <flux:badge size="sm" color="zinc">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($r->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $r->bill_date->toDateString() }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($r->total_cents / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No reimbursements yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Reimb #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->reimbursements as $r)
                    <tr data-test="reimbursement-row" class="cursor-pointer hover:bg-muted">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $r->bill_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('reimbursements.show', ['company' => $company->slug, 'bill' => $r->id]) }}" wire:navigate class="underline">{{ $r->bill_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($r->contact)->display_name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $r->due_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($r->balanceCents() / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($r->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                                @case('partial') <flux:badge color="indigo">{{ __('Partial') }}</flux:badge> @break
                                @case('paid') <flux:badge color="green">{{ __('Paid') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No reimbursements yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->reimbursements->links() }}</div>
</section>
