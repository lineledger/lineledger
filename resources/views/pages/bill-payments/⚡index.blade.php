<?php

use App\Models\BillPayment;
use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bill payments')] class extends Component
{
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function payments()
    {
        return BillPayment::query()
            ->with('contact', 'paidFromAccount')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('payment_no', 'like', '%'.$this->search.'%')
                    ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bill payments') }}</flux:heading>
            <flux:subheading>{{ __('Payments to vendors and employee reimbursements.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="filled" :href="route('bill-payments.batch', ['company' => $company->slug])" wire:navigate data-test="batch-pay-link">
                {{ __('Pay multiple suppliers') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" :href="route('bill-payments.create', ['company' => $company->slug])" wire:navigate data-test="new-payment-button">
                {{ __('Pay bills') }}
            </flux:button>
        </div>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search payment # or payee…') }}" icon="magnifying-glass" class="mb-4 max-w-md" />

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->payments as $p)
            <a href="{{ route('bill-payments.show', ['company' => $company->slug, 'payment' => $p->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="payment-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono font-medium">{{ $p->payment_no }}</span>
                    @switch($p->status->value)
                        @case('draft') <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge color="green" size="sm">{{ __('Posted') }}</flux:badge> @break
                        @case('void') <flux:badge color="zinc" size="sm">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ optional($p->contact)->display_name }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $p->payment_date->toDateString() }}</div>
                    <div class="text-right">
                        <div class="font-mono font-semibold">{{ number_format($p->amount_cents / 100, 2) }}</div>
                    </div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No payments yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Payment #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Payee') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Paid from') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->payments as $p)
                    <tr data-test="payment-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $p->payment_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('bill-payments.show', ['company' => $company->slug, 'payment' => $p->id]) }}" wire:navigate class="underline">{{ $p->payment_no }}</a></td>
                        <td class="px-4 py-2">{{ optional($p->contact)->display_name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $p->payment_type->label() }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($p->paidFromAccount)->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($p->amount_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($p->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No payments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->payments->links() }}</div>
</section>
