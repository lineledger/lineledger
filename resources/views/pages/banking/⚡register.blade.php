<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalLine;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Bank register')] class extends Component {
    public Company $company;

    public ?int $account_id = null;

    #[Url(as: 'statement')]
    public string $statementBalance = '';

    public bool $showCleared = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (request('account')) {
            $this->account_id = (int) request('account');
        } else {
            $first = Account::query()
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            $this->account_id = $first?->id;
        }
    }

    public function toggleClear(int $lineId): void
    {
        $line = JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('company_id', $this->company->id))
            ->findOrFail($lineId);

        $line->update(['cleared_at' => $line->cleared_at ? null : now()]);
    }

    public function clearAll(): void
    {
        $this->linesQuery()
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);

        Flux::toast(variant: 'success', text: __('All visible lines marked cleared.'));
    }

    public function uncleared(): void
    {
        $this->linesQuery()
            ->whereNotNull('cleared_at')
            ->update(['cleared_at' => null]);

        Flux::toast(variant: 'success', text: __('All lines marked uncleared.'));
    }

    protected function linesQuery()
    {
        return JournalLine::query()
            ->where('account_id', $this->account_id)
            ->where('is_posted', true);
    }

    #[Computed]
    public function lines()
    {
        return $this->linesQuery()
            ->with(['journalEntry'])
            ->when(! $this->showCleared, fn ($q) => $q->whereNull('cleared_at'))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    public function clearedBalanceCents(): int
    {
        return (int) $this->linesQuery()
            ->whereNotNull('cleared_at')
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }

    public function ledgerBalanceCents(): int
    {
        return (int) $this->linesQuery()
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }

    public function statementBalanceCents(): int
    {
        if ($this->statementBalance === '') {
            return 0;
        }

        try {
            return Money::fromString($this->statementBalance)->cents;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function differenceCents(): int
    {
        return $this->statementBalanceCents() - $this->clearedBalanceCents();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank register') }}</flux:heading>
            <flux:subheading>{{ __(':label. Click a row to mark cleared/uncleared against your statement.', ['label' => $company->jurisdiction->chequeLabel('checkbook')]) }}</flux:subheading>
        </div>

        <div class="flex items-end gap-3">
            <flux:select wire:model.live="account_id" :label="__('Account')" class="min-w-[260px]">
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:dropdown align="end">
                <flux:button variant="primary" icon:trailing="chevron-down" data-test="bank-register-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="check-circle" :href="route('banking.reconcile', ['company' => $company->slug, 'account' => $account_id])" wire:navigate data-test="reconcile-menu-item">
                        {{ __('Reconcile') }}
                    </flux:menu.item>
                    <flux:menu.item icon="arrow-up-tray" :href="route('banking.import', ['company' => $company->slug])" wire:navigate data-test="import-statement-menu-item">
                        {{ __('Import statement') }}
                    </flux:menu.item>
                    <flux:menu.item icon="funnel" :href="route('banking.rules', ['company' => $company->slug])" wire:navigate data-test="bank-rules-menu-item">
                        {{ __('Bank rules') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Ledger balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($this->ledgerBalanceCents() / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
            <div class="text-lg font-mono" data-test="cleared-balance">{{ number_format($this->clearedBalanceCents() / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:input wire:model.live="statementBalance" :label="__('Statement balance')" placeholder="0.00" />
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Difference') }}</flux:text>
            <div class="text-lg font-mono @if ($this->differenceCents() === 0) text-green-600 @else text-amber-600 @endif" data-test="reconciliation-difference">
                {{ number_format($this->differenceCents() / 100, 2) }}
            </div>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-4">
        <flux:switch wire:model.live="showCleared" :label="__('Show cleared')" />
        <flux:button variant="filled" size="sm" wire:click="clearAll" wire:confirm="{{ __('Mark all visible lines cleared?') }}">{{ __('Clear all') }}</flux:button>
        <flux:button variant="filled" size="sm" wire:click="uncleared" wire:confirm="{{ __('Mark all lines uncleared?') }}">{{ __('Unclear all') }}</flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 w-10"></th>
                    <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Entry #') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Payment') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Deposit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php $running = 0; @endphp
                @forelse ($this->lines as $line)
                    @php $running += (int) $line->debit_cents - (int) $line->credit_cents; @endphp
                    <tr data-test="register-row" class="@if ($line->cleared_at) bg-green-50 dark:bg-green-900/10 @endif">
                        <td class="px-3 py-2 text-center">
                            <button type="button" wire:click="toggleClear({{ $line->id }})" class="cursor-pointer" data-test="toggle-clear" title="{{ $line->cleared_at ? __('Cleared') : __('Click to mark cleared') }}">
                                @if ($line->cleared_at)
                                    <flux:icon name="check" variant="micro" class="size-4 text-green-600" />
                                @else
                                    <span class="inline-block size-4 rounded border border-border"></span>
                                @endif
                            </button>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                        <td class="px-3 py-2 font-mono">
                            <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry->memo }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $line->credit_cents > 0 ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $line->debit_cents > 0 ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ __('No transactions on this account.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
