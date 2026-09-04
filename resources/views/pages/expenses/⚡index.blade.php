<?php

use App\Models\Company;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Support\Contacts\ContactLinkResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Expenses')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'method')]
    public ?int $methodFilter = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function expenses()
    {
        return Expense::query()
            ->with('paymentAccount', 'paymentMethod', 'payee')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('reference', 'like', '%'.$this->search.'%')
                    ->orWhere('payee_name', 'like', '%'.$this->search.'%');
            }))
            ->when($this->methodFilter, fn ($q) => $q->where('payment_method_id', $this->methodFilter))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The linked payee's home page (statement, employee editor, or all-time
     * transactions), or null for a free-text payee or a viewer who cannot
     * reach that page's section — the name then renders as plain text.
     * payee is eager-loaded by expenses(), so this costs no extra queries.
     */
    public function payeeUrl(Expense $expense): ?string
    {
        return $expense->payee
            ? app(ContactLinkResolver::class)->urlForViewer($expense->payee, $this->company, Auth::user())
            : null;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
            <flux:subheading>{{ __('Pay-now spending by card, Interac, EFT, debit, or cash. To pay a bill, use Bill payments.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('expenses.create', ['company' => $company->slug])" wire:navigate data-test="new-expense-button">
            {{ __('New expense') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search reference or payee…')" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:select wire:model.live="methodFilter" class="sm:max-w-xs" data-test="expense-method-filter">
            <flux:select.option value="">{{ __('All payment methods') }}</flux:select.option>
            @foreach ($this->paymentMethodOptions as $opt)
                <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->expenses as $expense)
            <a href="{{ route('expenses.show', ['company' => $company->slug, 'expense' => $expense->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="expense-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $expense->payee_name }}</span>
                    @switch($expense->status->value)
                        @case('draft') <flux:badge size="sm" color="amber">{{ __('Draft') }}</flux:badge> @break
                        @case('posted') <flux:badge size="sm" color="green">{{ __('Posted') }}</flux:badge> @break
                        @case('void') <flux:badge size="sm" color="zinc">{{ __('Void') }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 text-xs text-muted-foreground">{{ optional($expense->paymentAccount)->name }}@if ($expense->paymentMethod) &middot; {{ $expense->paymentMethod->name }} @endif</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $expense->expense_date->toDateString() }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($expense->amount_cents / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No expenses yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Payee') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Paid from') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Method') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->expenses as $expense)
                    <tr data-test="expense-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $expense->expense_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono"><a href="{{ route('expenses.show', ['company' => $company->slug, 'expense' => $expense->id]) }}" wire:navigate class="underline">{{ $expense->reference ?: '—' }}</a></td>
                        <td class="px-4 py-2">
                            @php($payeeUrl = $this->payeeUrl($expense))
                            @if ($payeeUrl)
                                <a href="{{ $payeeUrl }}" wire:navigate class="underline" data-test="expense-payee-link">{{ $expense->payee_name }}</a>
                            @else
                                {{ $expense->payee_name }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($expense->paymentAccount)->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($expense->paymentMethod)->name ?: '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($expense->amount_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($expense->status->value)
                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                                @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No expenses yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->expenses->links() }}</div>
</section>
