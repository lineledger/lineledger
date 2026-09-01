<?php

use App\Enums\AccountSubtype;
use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Rules\MoneyString;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\DepositInTransitSync;
use App\Services\OpeningBalances\Importers\DepositsInTransitCsvImporter;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Deposits in transit')] class extends Component {
    use ImportsCsvList {
        runImport as baseRunImport;
    }
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    public ?int $editingId = null;

    public string $f_bank_account_id = '';

    public string $f_deposit_date = '';

    public string $f_description = '';

    public string $f_amount = '';

    public string $f_memo = '';

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->ownsCompany($company), 403);

        $this->company = $company;
        $this->resolveObState();
    }

    /** @return Collection<int, Deposit> */
    #[Computed]
    public function deposits(): Collection
    {
        return Deposit::query()
            ->with(['bankAccount', 'lines'])
            ->where('is_opening_balance', true)
            ->where('status', '!=', \App\Enums\DepositStatus::Void)
            ->orderBy('deposit_date')
            ->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function banks(): Collection
    {
        return Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('currency_code')->orWhere('currency_code', $this->company->currency_code))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function footer(): array
    {
        return app(OpeningBalanceStatusBuilder::class)->build($this->obState->refresh())['banks'];
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_bank_account_id', 'f_description', 'f_amount', 'f_memo']);
        $this->f_deposit_date = $this->obState->asOf()->toDateString();
        Flux::modal('ob-deposit')->show();
    }

    public function openEdit(int $id): void
    {
        $deposit = Deposit::query()->with('lines')->where('is_opening_balance', true)->findOrFail($id);

        $this->editingId = $deposit->id;
        $this->f_bank_account_id = (string) $deposit->bank_account_id;
        $this->f_deposit_date = $deposit->deposit_date->toDateString();
        $this->f_description = (string) ($deposit->lines->first()?->description ?? '');
        $this->f_amount = Money::fromCents((int) $deposit->amount_cents)->toDecimalString();
        $this->f_memo = (string) ($deposit->memo ?? '');

        Flux::modal('ob-deposit')->show();
    }

    public function save(): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $this->validate([
            'f_bank_account_id' => ['required', 'integer'],
            'f_deposit_date' => ['required', 'date'],
            'f_description' => ['nullable', 'string', 'max:255'],
            'f_amount' => ['required', new MoneyString],
            'f_memo' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'bank_account_id' => (int) $this->f_bank_account_id,
            'deposit_date' => CarbonImmutable::parse($this->f_deposit_date),
            'description' => $this->f_description !== '' ? $this->f_description : null,
            'amount_cents' => Money::fromString($this->f_amount)->cents,
            'memo' => $this->f_memo !== '' ? $this->f_memo : null,
        ];

        try {
            if ($this->editingId) {
                $deposit = Deposit::query()->where('is_opening_balance', true)->findOrFail($this->editingId);

                if ((int) $this->f_bank_account_id !== (int) $deposit->bank_account_id) {
                    Flux::toast(variant: 'danger', text: __('To move a deposit to another bank account, remove it and add it again.'));

                    return;
                }

                app(DepositInTransitSync::class)->update($this->obState, $deposit, $data);
            } else {
                app(DepositInTransitSync::class)->create($this->obState, $data);
            }
        } catch (RuntimeException|\App\Exceptions\Posting\PeriodLockedException|\App\Exceptions\Posting\ReconciliationLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->obState->refresh();
        Flux::modal('ob-deposit')->close();
        unset($this->deposits, $this->footer);
        Flux::toast(variant: 'success', text: __('Deposit in transit saved.'));
    }

    public function remove(int $id): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $deposit = Deposit::query()->where('is_opening_balance', true)->findOrFail($id);

        try {
            app(DepositInTransitSync::class)->remove($this->obState, $deposit);
        } catch (RuntimeException|\App\Exceptions\Posting\PeriodLockedException|\App\Exceptions\Posting\ReconciliationLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->obState->refresh();
        unset($this->deposits, $this->footer);
        Flux::toast(variant: 'success', text: __('Deposit removed (voided).'));
    }

    public function runImport(): void
    {
        $this->baseRunImport();
        unset($this->deposits, $this->footer);
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(DepositsInTransitCsvImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-deposits-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('deposits in transit');
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents, $this->company->currency_code ?? 'CAD')->format();
    }
}; ?>

<section class="w-full">
    @include('partials.opening-balances-heading')

    <div class="mb-4 flex items-center justify-between gap-3">
        <flux:text>
            {{ __('Deposits recorded in your previous system that had not reached a bank statement at the conversion date.') }}
        </flux:text>
        <div class="flex gap-2">
            <flux:button icon="arrow-up-tray" wire:click="openImport" :disabled="$obState->isFinalized()" data-test="ob-deposits-import-button">{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" :disabled="$obState->isFinalized()" data-test="ob-deposit-add">{{ __('Add deposit') }}</flux:button>
        </div>
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('Deposit #') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Date') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Description') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Bank') }}</th>
                        <th class="py-2 pr-3 text-right font-medium">{{ __('Amount') }}</th>
                        <th class="w-24 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->deposits as $deposit)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="ob-deposit-{{ $deposit->id }}" data-test="ob-deposit-row">
                            <td class="py-1.5 pr-3">{{ $deposit->deposit_no }}</td>
                            <td class="py-1.5 pr-3">{{ $deposit->deposit_date->toDateString() }}</td>
                            <td class="py-1.5 pr-3">{{ $deposit->lines->first()?->description ?? '—' }}</td>
                            <td class="py-1.5 pr-3">{{ $deposit->bankAccount?->name }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ $this->money((int) $deposit->amount_cents) }}</td>
                            <td class="py-1.5 text-right">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $deposit->id }})" :disabled="$obState->isFinalized()" data-test="ob-deposit-edit-{{ $deposit->id }}" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove({{ $deposit->id }})" wire:confirm="{{ __('Remove this deposit in transit? Its posting will be voided and the bank re-netted.') }}" :disabled="$obState->isFinalized()" data-test="ob-deposit-remove-{{ $deposit->id }}" />
                            </td>
                        </tr>
                    @endforeach

                    @if ($this->deposits->isEmpty())
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted-foreground">
                                {{ __('No deposits in transit recorded.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:modal name="ob-deposit" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? __('Edit deposit in transit') : __('Add deposit in transit') }}</flux:heading>

            <flux:select wire:model="f_bank_account_id" :label="__('Bank account')" data-test="ob-deposit-bank">
                <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                @foreach ($this->banks as $bank)
                    <flux:select.option value="{{ $bank->id }}">{{ $bank->code }} — {{ $bank->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="f_deposit_date" :label="__('Original date')" data-test="ob-deposit-date" />
            <flux:input wire:model="f_description" :label="__('Description (optional)')" data-test="ob-deposit-description" />
            <x-amount-input model="f_amount" modifiers="" :label="__('Amount')" data-test="ob-deposit-amount" />
            <flux:input wire:model="f_memo" :label="__('Memo (optional)')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="save" data-test="ob-deposit-save">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <x-csv-import-modal
        name="ob-deposits-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => 'deposits_in_transit'])"
        :subtitle="__('Deposits in transit at the conversion date. Deposits get generated numbers, so import this file once.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
