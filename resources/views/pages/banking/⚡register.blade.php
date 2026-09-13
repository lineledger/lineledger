<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalLine;
use App\Support\Banking\LastBankAccount;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank register')] class extends Component {
    public Company $company;

    public ?int $account_id = null;

    public bool $showCleared = true;

    public string $sortField = 'entry_date';

    public string $sortDir = 'asc';

    private const SORT_FIELDS = ['entry_date', 'entry_no'];

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (request('account')) {
            $this->account_id = (int) request('account');
        } else {
            // Reopen on the account this operator last worked in anywhere in
            // Banking; fall back to the lowest-numbered active account.
            $first = Account::query()
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            $this->account_id = LastBankAccount::recall($company, $this->bankAccounts) ?? $first?->id;
        }

        LastBankAccount::remember($company, $this->account_id);
    }

    public function updatedAccountId(): void
    {
        LastBankAccount::remember($this->company, $this->account_id);
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    protected function linesQuery()
    {
        return JournalLine::query()
            ->where('journal_lines.account_id', $this->account_id)
            ->where('journal_lines.is_posted', true);
    }

    #[Computed]
    public function lines()
    {
        $query = $this->linesQuery()
            ->with(['journalEntry'])
            // Hiding cleared rows leaves what is still outstanding, and a
            // voided cheque and its reversal never are: neither reaches the bank.
            ->when(! $this->showCleared, fn ($q) => $q->whereNull('cleared_at')->withoutUnsettledVoids());

        if ($this->sortField === 'entry_no') {
            $query->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->orderBy('journal_entries.entry_no', $this->sortDir)
                ->orderBy('journal_lines.id', $this->sortDir)
                ->select('journal_lines.*');
        } else {
            $query->orderBy('entry_date', $this->sortDir)
                ->orderBy('id', $this->sortDir);
        }

        return $query->get();
    }

    /**
     * Ids of the rows that are one half of a void nobody has cleared, so the
     * register can mark them void rather than as waiting to clear.
     *
     * @return array<int, true>
     */
    #[Computed]
    public function voidLineIds(): array
    {
        return $this->linesQuery()
            ->unsettledVoids()
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
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
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank register') }}</flux:heading>
            <flux:subheading>{{ __(':label. Cleared rows are ticked off during reconciliation.', ['label' => $company->jurisdiction->chequeLabel('checkbook')]) }}</flux:subheading>
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

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Ledger balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($this->ledgerBalanceCents() / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
            <div class="text-lg font-mono" data-test="cleared-balance">{{ number_format($this->clearedBalanceCents() / 100, 2) }}</div>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-4">
        <flux:switch wire:model.live="showCleared" :label="__('Show cleared')" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 w-10"></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="entry_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="entry_no" :current-field="$sortField" :current-dir="$sortDir" :label="__('Entry #')" /></th>
                    <th class="px-3 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Payment') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Deposit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php $running = 0; @endphp
                @forelse ($this->lines as $line)
                    @php $running += (int) $line->debit_cents - (int) $line->credit_cents; @endphp
                    @php $isVoid = isset($this->voidLineIds[$line->id]); @endphp
                    <tr data-test="register-row" class="@if ($line->cleared_at) bg-green-50 dark:bg-green-900/10 @elseif ($isVoid) text-muted-foreground @endif">
                        <td class="px-3 py-2 text-center">
                            @if ($line->cleared_at)
                                <flux:icon name="check" variant="micro" class="size-4 text-green-600" title="{{ __('Cleared') }}" data-test="register-cleared-mark" />
                            @elseif ($isVoid)
                                <flux:icon name="no-symbol" variant="micro" class="size-4 text-muted-foreground" title="{{ __('Voided — this and its reversal cancel out and never reach the bank') }}" data-test="register-void-mark" />
                            @else
                                <span class="inline-block size-4 rounded border border-border" title="{{ __('Not cleared') }}"></span>
                            @endif
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
