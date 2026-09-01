<?php

use App\Enums\AccountSubtype;
use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Rules\MoneyString;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\Importers\OutstandingChequesCsvImporter;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Services\OpeningBalances\OutstandingChequeSync;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Outstanding cheques')] class extends Component {
    use ImportsCsvList {
        runImport as baseRunImport;
    }
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    public ?int $editingId = null;

    public string $f_bank_account_id = '';

    public string $f_cheque_no = '';

    public string $f_cheque_date = '';

    public string $f_payee_name = '';

    public string $f_amount = '';

    public string $f_memo = '';

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->ownsCompany($company), 403);

        $this->company = $company;
        $this->resolveObState();
    }

    /** @return Collection<int, Cheque> */
    #[Computed]
    public function cheques(): Collection
    {
        return Cheque::query()
            ->with('bankAccount')
            ->where('is_opening_balance', true)
            ->where('status', '!=', \App\Enums\ChequeStatus::Void)
            ->orderBy('cheque_date')
            ->orderBy('cheque_no')
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
        $this->reset(['editingId', 'f_bank_account_id', 'f_cheque_no', 'f_payee_name', 'f_amount', 'f_memo']);
        $this->f_cheque_date = $this->obState->asOf()->toDateString();
        Flux::modal('ob-cheque')->show();
    }

    public function openEdit(int $id): void
    {
        $cheque = Cheque::query()->where('is_opening_balance', true)->findOrFail($id);

        $this->editingId = $cheque->id;
        $this->f_bank_account_id = (string) $cheque->bank_account_id;
        $this->f_cheque_no = (string) $cheque->cheque_no;
        $this->f_cheque_date = $cheque->cheque_date->toDateString();
        $this->f_payee_name = (string) $cheque->payee_name;
        $this->f_amount = Money::fromCents((int) $cheque->amount_cents)->toDecimalString();
        $this->f_memo = (string) ($cheque->memo ?? '');

        Flux::modal('ob-cheque')->show();
    }

    public function save(): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $this->validate([
            'f_bank_account_id' => ['required', 'integer'],
            'f_cheque_no' => ['required', 'string', 'max:50'],
            'f_cheque_date' => ['required', 'date'],
            'f_payee_name' => ['required', 'string', 'max:255'],
            'f_amount' => ['required', new MoneyString],
            'f_memo' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'bank_account_id' => (int) $this->f_bank_account_id,
            'cheque_no' => $this->f_cheque_no,
            'cheque_date' => CarbonImmutable::parse($this->f_cheque_date),
            'payee_name' => $this->f_payee_name,
            'amount_cents' => Money::fromString($this->f_amount)->cents,
            'memo' => $this->f_memo !== '' ? $this->f_memo : null,
        ];

        try {
            if ($this->editingId) {
                $cheque = Cheque::query()->where('is_opening_balance', true)->findOrFail($this->editingId);
                app(OutstandingChequeSync::class)->update($this->obState, $cheque, $data);
            } else {
                app(OutstandingChequeSync::class)->create($this->obState, $data);
            }
        } catch (RuntimeException|\App\Exceptions\Posting\PeriodLockedException|\App\Exceptions\Posting\ReconciliationLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->obState->refresh();
        Flux::modal('ob-cheque')->close();
        unset($this->cheques, $this->footer);
        Flux::toast(variant: 'success', text: __('Outstanding cheque saved.'));
    }

    public function remove(int $id): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $cheque = Cheque::query()->where('is_opening_balance', true)->findOrFail($id);

        try {
            app(OutstandingChequeSync::class)->remove($this->obState, $cheque);
        } catch (RuntimeException|\App\Exceptions\Posting\PeriodLockedException|\App\Exceptions\Posting\ReconciliationLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->obState->refresh();
        unset($this->cheques, $this->footer);
        Flux::toast(variant: 'success', text: __('Cheque removed (voided).'));
    }

    public function runImport(): void
    {
        $this->baseRunImport();
        unset($this->cheques, $this->footer);
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(OutstandingChequesCsvImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-cheques-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('outstanding cheques');
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
            {{ __('Cheques written in your previous system that had not cleared the bank at the conversion date. Each posts at its original date so a future reconciliation can tick it when it cashes.') }}
        </flux:text>
        <div class="flex gap-2">
            <flux:button icon="arrow-up-tray" wire:click="openImport" :disabled="$obState->isFinalized()" data-test="ob-cheques-import-button">{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" :disabled="$obState->isFinalized()" data-test="ob-cheque-add">{{ __('Add cheque') }}</flux:button>
        </div>
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('Cheque #') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Date') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Payee') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('Bank') }}</th>
                        <th class="py-2 pr-3 text-right font-medium">{{ __('Amount') }}</th>
                        <th class="w-24 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->cheques as $cheque)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="ob-cheque-{{ $cheque->id }}" data-test="ob-cheque-row">
                            <td class="py-1.5 pr-3">{{ $cheque->cheque_no }}</td>
                            <td class="py-1.5 pr-3">{{ $cheque->cheque_date->toDateString() }}</td>
                            <td class="py-1.5 pr-3">{{ $cheque->payee_name }}</td>
                            <td class="py-1.5 pr-3">{{ $cheque->bankAccount?->name }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ $this->money((int) $cheque->amount_cents) }}</td>
                            <td class="py-1.5 text-right">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $cheque->id }})" :disabled="$obState->isFinalized()" data-test="ob-cheque-edit-{{ $cheque->cheque_no }}" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="remove({{ $cheque->id }})" wire:confirm="{{ __('Remove this outstanding cheque? Its posting will be voided and the bank re-netted.') }}" :disabled="$obState->isFinalized()" data-test="ob-cheque-remove-{{ $cheque->cheque_no }}" />
                            </td>
                        </tr>
                    @endforeach

                    @if ($this->cheques->isEmpty())
                        <tr>
                            <td colspan="6" class="py-8 text-center text-muted-foreground">
                                {{ __('No outstanding cheques recorded.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </flux:card>

    @if ($this->footer !== [])
        <flux:card class="mt-6">
            <flux:heading size="lg" class="mb-2">{{ __('Bank math') }}</flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3 font-medium">{{ __('Account') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Book balance (target)') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Outstanding cheques') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Deposits in transit') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('First rec beginning balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->footer as $bank)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-3">{{ $bank['code'] }} — {{ $bank['name'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['book_target']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['outstanding_cheques']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['deposits_in_transit']) }}</td>
                                <td class="py-2 text-right font-medium tabular-nums">{{ $this->money($bank['statement_side']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    <flux:modal name="ob-cheque" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? __('Edit outstanding cheque') : __('Add outstanding cheque') }}</flux:heading>

            <flux:select wire:model="f_bank_account_id" :label="__('Bank account')" data-test="ob-cheque-bank">
                <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                @foreach ($this->banks as $bank)
                    <flux:select.option value="{{ $bank->id }}">{{ $bank->code }} — {{ $bank->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="f_cheque_no" :label="__('Cheque #')" data-test="ob-cheque-no" />
                <flux:input type="date" wire:model="f_cheque_date" :label="__('Original date')" data-test="ob-cheque-date" />
            </div>

            <flux:input wire:model="f_payee_name" :label="__('Payee')" data-test="ob-cheque-payee" />
            <x-amount-input model="f_amount" modifiers="" :label="__('Amount')" data-test="ob-cheque-amount" />
            <flux:input wire:model="f_memo" :label="__('Memo (optional)')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="save" data-test="ob-cheque-save">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <x-csv-import-modal
        name="ob-cheques-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => 'outstanding_cheques'])"
        :subtitle="__('Your QuickBooks outstanding-cheques list. Rows whose cheque number already exists on that bank are skipped.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
