<?php

use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Account;
use App\Models\Company;
use App\Models\OpeningBalanceRow;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\Importers\OpeningTrialBalanceCsvImporter;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Opening trial balance')] class extends Component {
    use ImportsCsvList {
        runImport as baseRunImport;
    }
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    /** @var array<int|string, string> Draft debit inputs keyed by account id. */
    public array $d = [];

    /** @var array<int|string, string> Draft credit inputs keyed by account id. */
    public array $c = [];

    public ?int $newAccountId = null;

    public string $newDebit = '';

    public string $newCredit = '';

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->ownsCompany($company), 403);

        $this->company = $company;
        $this->resolveObState();
        $this->seedInputs();
    }

    protected function seedInputs(): void
    {
        $this->d = [];
        $this->c = [];

        foreach ($this->obState->rows()->get() as $row) {
            $this->d[$row->account_id] = $row->debit_cents > 0 ? Money::fromCents($row->debit_cents)->toDecimalString() : '';
            $this->c[$row->account_id] = $row->credit_cents > 0 ? Money::fromCents($row->credit_cents)->toDecimalString() : '';
        }
    }

    /** @return Collection<int, OpeningBalanceRow> */
    #[Computed]
    public function rows(): Collection
    {
        return $this->obState->rows()->with('account')->get()
            ->sortBy(fn ($row) => (string) $row->account?->code)
            ->values();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function availableAccounts(): Collection
    {
        $targeted = $this->obState->rows()->pluck('account_id');

        return Account::query()
            ->where('is_active', true)
            ->whereNotIn('id', $targeted)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->obState->rows()->get();
        $debit = (int) $rows->sum('debit_cents');
        $credit = (int) $rows->sum('credit_cents');

        return ['debit' => $debit, 'credit' => $credit, 'imbalance' => $debit - $credit];
    }

    public function updatedD($value, $key): void
    {
        $this->saveCell((int) $key, 'debit', (string) $value);
    }

    public function updatedC($value, $key): void
    {
        $this->saveCell((int) $key, 'credit', (string) $value);
    }

    protected function saveCell(int $accountId, string $side, string $value): void
    {
        if (! $this->obEditable()) {
            $this->seedInputs();

            return;
        }

        $money = $value === '' ? Money::zero() : Money::tryFromString($value);

        if ($money === null || $money->cents < 0) {
            Flux::toast(variant: 'danger', text: __('Enter a valid amount, e.g. 100.00.'));
            $this->seedInputs();

            return;
        }

        $row = $this->obState->rows()->where('account_id', $accountId)->first();

        if (! $row) {
            return;
        }

        // A trial balance row carries one side only — filling one clears the other.
        $row->update([
            'debit_cents' => $side === 'debit' ? $money->cents : 0,
            'credit_cents' => $side === 'credit' ? $money->cents : 0,
            'updated_by_user_id' => auth()->id(),
        ]);

        $this->applyToBooks();
        $this->seedInputs();
        unset($this->rows, $this->totals);
    }

    public function addRow(): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $this->validate([
            'newAccountId' => ['required', 'integer'],
            'newDebit' => ['nullable', new \App\Rules\MoneyString],
            'newCredit' => ['nullable', new \App\Rules\MoneyString],
        ]);

        $account = Account::query()->findOrFail($this->newAccountId);
        $debit = $this->newDebit !== '' ? Money::fromString($this->newDebit)->cents : 0;
        $credit = $this->newCredit !== '' ? Money::fromString($this->newCredit)->cents : 0;

        if ($debit < 0 || $credit < 0 || ($debit !== 0 && $credit !== 0)) {
            Flux::toast(variant: 'danger', text: __('Enter a positive amount on one side only.'));

            return;
        }

        OpeningBalanceRow::withoutGlobalScopes()->updateOrCreate(
            ['opening_balance_state_id' => $this->obState->id, 'account_id' => $account->id],
            [
                'company_id' => $this->company->id,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'updated_by_user_id' => auth()->id(),
            ],
        );

        $this->applyToBooks();
        $this->reset(['newAccountId', 'newDebit', 'newCredit']);
        $this->seedInputs();
        unset($this->rows, $this->totals, $this->availableAccounts);
    }

    public function removeRow(int $accountId): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $this->obState->rows()->where('account_id', $accountId)->delete();

        $this->applyToBooks();
        $this->seedInputs();
        unset($this->rows, $this->totals, $this->availableAccounts);
    }

    public function runImport(): void
    {
        $this->baseRunImport();
        $this->seedInputs();
        unset($this->rows, $this->totals, $this->availableAccounts);
    }

    /** The TB import also updates and removes rows — enable the button for those. */
    #[Computed]
    public function importCreatableCount(): int
    {
        return collect($this->importPreviewRows ?? [])
            ->whereIn('action', ['create', 'update', 'remove'])
            ->count();
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(OpeningTrialBalanceCsvImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-tb-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('trial balance rows');
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
            {{ __('Your draft trial balance as of :date. Every save updates the books; AR, AP and Inventory rows are targets for their own tabs and are never posted from here.', ['date' => $obState->asOf()->format('F j, Y')]) }}
        </flux:text>
        <flux:button icon="arrow-up-tray" wire:click="openImport" :disabled="$obState->isFinalized()" data-test="ob-tb-import-button">{{ __('Import') }}</flux:button>
    </div>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('Account') }}</th>
                        <th class="w-40 py-2 pr-3 text-right font-medium">{{ __('Debit') }}</th>
                        <th class="w-40 py-2 pr-3 text-right font-medium">{{ __('Credit') }}</th>
                        <th class="w-10 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows as $row)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="ob-row-{{ $row->account_id }}" data-test="ob-tb-row">
                            <td class="py-1.5 pr-3">
                                {{ $row->account?->code }} — {{ $row->account?->name }}
                                @if ($row->account && in_array($row->account->subtype->value, ['accounts_receivable', 'accounts_payable', 'inventory'], true))
                                    <flux:badge size="sm" color="amber" class="ms-1">{{ __('sub-ledger target') }}</flux:badge>
                                @endif
                            </td>
                            <td class="py-1.5 pr-3">
                                <x-amount-input model="d.{{ $row->account_id }}" modifiers=".live.blur" placeholder="0.00" class="text-right" :disabled="$obState->isFinalized()" data-test="ob-tb-debit-{{ $row->account?->code }}" />
                            </td>
                            <td class="py-1.5 pr-3">
                                <x-amount-input model="c.{{ $row->account_id }}" modifiers=".live.blur" placeholder="0.00" class="text-right" :disabled="$obState->isFinalized()" data-test="ob-tb-credit-{{ $row->account?->code }}" />
                            </td>
                            <td class="py-1.5 text-right">
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeRow({{ $row->account_id }})" wire:confirm="{{ __('Remove this target? The books will be re-netted without it.') }}" :disabled="$obState->isFinalized()" data-test="ob-tb-remove-{{ $row->account?->code }}" />
                            </td>
                        </tr>
                    @endforeach

                    @if ($this->rows->isEmpty())
                        <tr>
                            <td colspan="4" class="py-8 text-center text-muted-foreground">
                                {{ __('No targets yet — import your QuickBooks trial balance or add accounts below.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-200 font-medium dark:border-zinc-700">
                        <td class="py-2 pr-3">{{ __('Totals') }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums" data-test="ob-tb-total-debit">{{ $this->money($this->totals['debit']) }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums" data-test="ob-tb-total-credit">{{ $this->money($this->totals['credit']) }}</td>
                        <td></td>
                    </tr>
                    @if ($this->totals['imbalance'] !== 0)
                        <tr class="text-amber-600">
                            <td class="py-1 pr-3">{{ __('Out of balance (goes to Opening Balance Equity)') }}</td>
                            <td colspan="2" class="py-1 pr-3 text-right tabular-nums">{{ $this->money($this->totals['imbalance']) }}</td>
                            <td></td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>

        {{-- Add a target --}}
        <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <flux:select wire:model="newAccountId" :label="__('Add account')" class="min-w-64" data-test="ob-tb-new-account">
                <flux:select.option value="">{{ __('Choose an account…') }}</flux:select.option>
                @foreach ($this->availableAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <x-amount-input model="newDebit" modifiers="" :label="__('Debit')" class="w-36 text-right" data-test="ob-tb-new-debit" />
            <x-amount-input model="newCredit" modifiers="" :label="__('Credit')" class="w-36 text-right" data-test="ob-tb-new-credit" />
            <flux:button wire:click="addRow" :disabled="$obState->isFinalized()" data-test="ob-tb-add">{{ __('Add') }}</flux:button>
        </div>
    </flux:card>

    <x-csv-import-modal
        name="ob-tb-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => 'trial_balance'])"
        :subtitle="__('Your QuickBooks trial balance export. Re-importing replaces the whole draft; rows missing from the file are removed.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
