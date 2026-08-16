<?php

use App\Models\Cheque;
use App\Models\Company;
use App\Models\PayrollCheque;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cheques')] class extends Component {
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function allCheques(): LengthAwarePaginator
    {
        $expense = Cheque::query()
            ->with('bankAccount')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('cheque_no', 'like', '%'.$this->search.'%')
                    ->orWhere('payee_name', 'like', '%'.$this->search.'%');
            }))
            ->get()
            ->map(fn ($c) => [
                'sort_key' => $c->cheque_date->toDateString().'_'.str_pad((string) $c->id, 10, '0', STR_PAD_LEFT),
                'type' => 'expense',
                'cheque_no' => $c->cheque_no,
                'cheque_date' => $c->cheque_date->toDateString(),
                'payee_name' => $c->payee_name,
                'bank_name' => $c->bankAccount?->name,
                'amount_cents' => $c->amount_cents,
                'status' => $c->status->value,
                'href' => route('cheques.show', ['company' => $this->company->slug, 'cheque' => $c->id]),
                'pay_run_no' => null,
                'pay_run_href' => null,
            ]);

        $payroll = $this->company->usesPayroll()
            ? PayrollCheque::query()
                ->with('bankAccount', 'payRun:id,run_no')
                ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                    $q->where('cheque_no', 'like', '%'.$this->search.'%')
                        ->orWhere('payee_name', 'like', '%'.$this->search.'%');
                }))
                ->get()
                ->map(fn ($c) => [
                    'sort_key' => $c->cheque_date->toDateString().'_'.str_pad((string) $c->id, 10, '0', STR_PAD_LEFT),
                    'type' => 'payroll',
                    'cheque_no' => $c->cheque_no,
                    'cheque_date' => $c->cheque_date->toDateString(),
                    'payee_name' => $c->payee_name,
                    'bank_name' => $c->bankAccount?->name,
                    'amount_cents' => $c->amount_cents,
                    'status' => $c->status->value,
                    'href' => route('pay-runs.show', ['company' => $this->company->slug, 'payRun' => $c->pay_run_id]),
                    'pay_run_no' => $c->payRun?->run_no,
                    'pay_run_href' => route('pay-runs.show', ['company' => $this->company->slug, 'payRun' => $c->pay_run_id]),
                ])
            : collect();

        $merged = $expense->concat($payroll)->sortByDesc('sort_key')->values();

        $perPage = 25;
        $page = max(1, (int) request()->get('page', 1));

        return new LengthAwarePaginator(
            $merged->forPage($page, $perPage),
            $merged->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}; ?>

<section class="w-full">
    @php($j = $company->jurisdiction)
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $j->cheque('plural') }}</flux:heading>
            <flux:subheading>{{ __('Expense and payroll :plural. For bill payments, use Bill payments.', ['plural' => mb_strtolower($j->cheque('plural'))]) }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('cheques.create', ['company' => $company->slug])" wire:navigate data-test="new-cheque-button">
            {{ $j->chequeLabel('write') }}
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search :label or payee…', ['label' => $j->chequeLabel('number')])" icon="magnifying-glass" class="max-w-md" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->allCheques as $cheque)
            <a href="{{ $cheque['href'] }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="cheque-card">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-medium">{{ $cheque['cheque_no'] }}</span>
                        @if ($cheque['type'] === 'payroll')
                            <flux:badge size="sm" color="blue">{{ __('Payroll') }}</flux:badge>
                        @endif
                    </div>
                    @switch($cheque['status'])
                        @case('draft') <flux:badge size="sm" color="amber">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge size="sm" color="green">{{ __('Posted') }}</flux:badge> @break
                        @case('void') <flux:badge size="sm" color="zinc">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ $cheque['payee_name'] }}</div>
                <div class="mt-1 text-xs text-muted-foreground">
                    {{ $cheque['bank_name'] }}
                    @if ($cheque['pay_run_no']) · {{ $cheque['pay_run_no'] }} @endif
                </div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $cheque['cheque_date'] }}</div>
                    <div class="font-mono font-semibold">{{ number_format($cheque['amount_cents'] / 100, 2) }}</div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No :plural yet.', ['plural' => mb_strtolower($j->cheque('plural'))]) }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ $j->chequeLabel('number') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Payee') }}</th>
                    @if ($company->usesPayroll())
                        <th class="px-4 py-2 text-left">{{ __('Pay run') }}</th>
                    @endif
                    <th class="px-4 py-2 text-left">{{ __('Bank') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->allCheques as $cheque)
                    <tr data-test="cheque-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $cheque['cheque_date'] }}</td>
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ $cheque['href'] }}" wire:navigate class="underline">{{ $cheque['cheque_no'] }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $cheque['payee_name'] }}</td>
                        @if ($company->usesPayroll())
                            <td class="px-4 py-2 text-muted-foreground">
                                @if ($cheque['pay_run_href'])
                                    <a href="{{ $cheque['pay_run_href'] }}" wire:navigate class="underline">{{ $cheque['pay_run_no'] }}</a>
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-2 text-muted-foreground">{{ $cheque['bank_name'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($cheque['amount_cents'] / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($cheque['status'])
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $company->usesPayroll() ? 7 : 6 }}" class="px-4 py-8 text-center text-muted-foreground">{{ __('No :plural yet.', ['plural' => mb_strtolower($j->cheque('plural'))]) }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->allCheques->links() }}</div>
</section>
