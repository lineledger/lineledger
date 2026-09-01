<?php

use App\Enums\AuditAction;
use App\Livewire\Concerns\ImportsCsvList;
use App\Livewire\Concerns\InteractsWithOpeningBalances;
use App\Models\Company;
use App\Models\EmployeePayrollProfile;
use App\Models\OpeningBalanceState;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\Importers\FixedAssetsCompanyImporter;
use App\Services\OpeningBalances\Importers\InventoryOpeningBalanceCompanyImporter;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Opening balances')] class extends Component {
    use ImportsCsvList;
    use InteractsWithOpeningBalances;
    use WithFileUploads;

    public Company $company;

    public string $asOfDate = '';

    /** Which extra importer the shared CSV modal is currently serving. */
    public string $extraImportKind = 'inventory';

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->ownsCompany($company), 403);

        $this->company = $company;
        $this->resolveObState();
        $this->asOfDate = $this->obState->asOf()->toDateString();
    }

    #[Computed]
    public function status(): array
    {
        return app(OpeningBalanceStatusBuilder::class)->build($this->obState->refresh());
    }

    public function saveAsOf(): void
    {
        if (! $this->obEditable()) {
            return;
        }

        $this->validate(['asOfDate' => ['required', 'date']]);

        if ($this->obState->asOf()->toDateString() === $this->asOfDate) {
            return;
        }

        $this->obState->update(['as_of_date' => $this->asOfDate]);
        $this->applyToBooks();
        unset($this->status);

        Flux::toast(variant: 'success', text: __('As-of date moved to :date. The opening entry was re-dated; existing detail documents keep their own dates.', ['date' => $this->asOfDate]));
    }

    public function finalize(): void
    {
        $this->obState->refresh();

        if ($this->obState->isFinalized()) {
            return;
        }

        $previousLock = $this->company->lock_date?->toDateString();
        $newLock = $this->obState->asOf()->toDateString();

        $this->company->update(['lock_date' => $newLock]);
        $this->obState->update(['status' => OpeningBalanceState::STATUS_FINALIZED]);

        app(AccountingAuditRecorder::class)->record(
            (int) $this->company->id,
            AuditAction::PeriodLockChanged,
            $this->company,
            ['from' => $previousLock, 'to' => $newLock, 'reason' => 'opening_balances_finalized'],
        );

        unset($this->status);
        Flux::toast(variant: 'success', text: __('Opening balances finalized — books locked through :date.', ['date' => $newLock]));
    }

    public function unfinalize(): void
    {
        $this->obState->refresh();

        if (! $this->obState->isFinalized()) {
            return;
        }

        $previousLock = $this->company->lock_date?->toDateString();

        // Only release the lock we set: a lock moved later (e.g. a filed year)
        // is someone else's decision to undo.
        if ($previousLock === $this->obState->asOf()->toDateString()) {
            $this->company->update(['lock_date' => null]);

            app(AccountingAuditRecorder::class)->record(
                (int) $this->company->id,
                AuditAction::PeriodLockChanged,
                $this->company,
                ['from' => $previousLock, 'to' => null, 'reason' => 'opening_balances_unfinalized'],
            );
        }

        $this->obState->update(['status' => OpeningBalanceState::STATUS_ACTIVE]);

        unset($this->status);
        Flux::toast(variant: 'success', text: __('Opening balances re-opened for editing.'));
    }

    public function openExtraImport(string $kind): void
    {
        abort_unless(in_array($kind, ['inventory', 'fixed_assets'], true), 404);

        $this->extraImportKind = $kind;
        $this->openImport();
    }

    #[Computed]
    public function payrollYtd(): ?array
    {
        if (! $this->company->usesPayroll()) {
            return null;
        }

        $profiles = EmployeePayrollProfile::query()->get();

        return [
            'total' => $profiles->count(),
            'with_opening' => $profiles->filter(fn ($p) => $p->opening_balances_as_of !== null)->count(),
        ];
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return $this->extraImportKind === 'fixed_assets'
            ? app(FixedAssetsCompanyImporter::class)
            : app(InventoryOpeningBalanceCompanyImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'ob-extra-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return $this->extraImportKind === 'fixed_assets' ? __('fixed assets') : __('inventory items');
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents, $this->company->currency_code ?? 'CAD')->format();
    }
}; ?>

<section class="w-full">
    @include('partials.opening-balances-heading')

    @php($s = $this->status)

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- As-of + actions --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Conversion date') }}</flux:heading>
            <flux:text>{{ __('The date your balances are carried over as of — usually your last fiscal year-end in the old system.') }}</flux:text>
            <div class="flex items-end gap-2">
                <flux:input type="date" wire:model="asOfDate" :label="__('As of')" :disabled="$obState->isFinalized()" data-test="ob-as-of" />
                <flux:button wire:click="saveAsOf" :disabled="$obState->isFinalized()" data-test="ob-save-as-of">{{ __('Save') }}</flux:button>
            </div>

            @if ($obState->isFinalized())
                <flux:button variant="filled" wire:click="unfinalize" wire:confirm="{{ __('Re-open opening balances for editing? This lifts the period lock this workspace set.') }}" data-test="ob-unfinalize">
                    {{ __('Un-finalize') }}
                </flux:button>
            @else
                <flux:button variant="filled" wire:click="finalize" wire:confirm="{{ __('Finalize opening balances? This locks the books through the as-of date. You can un-finalize later.') }}" data-test="ob-finalize">
                    {{ __('Finalize & lock') }}
                </flux:button>
            @endif
        </flux:card>

        {{-- Draft TB summary --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Draft trial balance') }}</flux:heading>
            <div class="text-sm space-y-1">
                <div class="flex justify-between"><span>{{ __('Targets (debits)') }}</span><span class="tabular-nums">{{ $this->money($s['targets']['debit']) }}</span></div>
                <div class="flex justify-between"><span>{{ __('Targets (credits)') }}</span><span class="tabular-nums">{{ $this->money($s['targets']['credit']) }}</span></div>
                <div class="flex justify-between font-medium {{ $s['targets']['imbalance'] === 0 ? 'text-emerald-600' : 'text-amber-600' }}" data-test="ob-tb-imbalance">
                    <span>{{ __('Imbalance') }}</span><span class="tabular-nums">{{ $this->money($s['targets']['imbalance']) }}</span>
                </div>
            </div>
            <flux:button size="sm" variant="filled" :href="route('opening-balances.trial-balance', ['company' => $company->slug])" wire:navigate>
                {{ __('Edit the draft') }}
            </flux:button>
        </flux:card>

        {{-- OBE residual --}}
        <flux:card class="space-y-3" data-test="ob-residual-card">
            <flux:heading size="lg">{{ __('Unexplained balance') }}</flux:heading>
            <div class="text-3xl font-semibold tabular-nums {{ $s['obe']['residual'] === 0 ? 'text-emerald-600' : 'text-amber-600' }}" data-test="ob-residual">
                {{ $this->money($s['obe']['residual']) }}
            </div>
            <flux:text>
                {{ $s['obe']['residual'] === 0
                    ? __('Everything ties: the books fully explain the draft trial balance.')
                    : __('Sitting in Opening Balance Equity until the remaining detail is entered or the draft is corrected.') }}
            </flux:text>
        </flux:card>
    </div>

    {{-- AR / AP ties --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach ([['ar', __('Customers owe you (AR)'), 'opening-balances.receivables'], ['ap', __('You owe vendors (AP)'), 'opening-balances.payables']] as [$key, $label, $routeName])
            @php($block = $s[$key])
            <flux:card class="space-y-3" data-test="ob-{{ $key }}-card">
                <flux:heading size="lg">{{ $label }}</flux:heading>
                <div class="text-sm space-y-1">
                    <div class="flex justify-between"><span>{{ __('Draft target') }}</span><span class="tabular-nums">{{ $this->money(abs($block['target'])) }}</span></div>
                    <div class="flex justify-between"><span>{{ __('Entered per contact') }}</span><span class="tabular-nums">{{ $this->money($block['detail']) }}</span></div>
                    @php($variance = ($key === 'ap' ? -$block['target'] : $block['target']) - ($key === 'ap' ? -$block['gl'] : $block['gl']))
                    <div class="flex justify-between font-medium {{ $variance === 0 ? 'text-emerald-600' : 'text-amber-600' }}" data-test="ob-{{ $key }}-variance">
                        <span>{{ __('Still to explain') }}</span><span class="tabular-nums">{{ $this->money(abs($variance)) }}</span>
                    </div>
                </div>
                <flux:button size="sm" variant="filled" :href="route($routeName, ['company' => $company->slug])" wire:navigate>
                    {{ __('Enter balances') }}
                </flux:button>
            </flux:card>
        @endforeach
    </div>

    {{-- Banks --}}
    @if ($s['banks'] !== [])
        <flux:card class="mt-6 space-y-3">
            <flux:heading size="lg">{{ __('Bank accounts') }}</flux:heading>
            <flux:text>{{ __('When you run each account’s first reconciliation, use the statement-side figure below as the beginning balance — the opening entry’s bank line is already marked cleared for you.') }}</flux:text>
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
                        @foreach ($s['banks'] as $bank)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800" data-test="ob-bank-row">
                                <td class="py-2 pr-3">{{ $bank['code'] }} — {{ $bank['name'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['book_target']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['outstanding_cheques']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $this->money($bank['deposits_in_transit']) }}</td>
                                <td class="py-2 text-right font-medium tabular-nums" data-test="ob-bank-statement-side">{{ $this->money($bank['statement_side']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    {{-- Inventory / fixed assets / payroll --}}
    <flux:card class="mt-6 space-y-4">
        <flux:heading size="lg">{{ __('Other opening data') }}</flux:heading>
        <div class="grid gap-4 lg:grid-cols-3 text-sm">
            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Inventory on hand') }}</flux:heading>
                <flux:text>{{ __('Load item quantities and values as of the conversion date.') }}</flux:text>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="filled" wire:click="openExtraImport('inventory')" :disabled="$obState->isFinalized()" data-test="ob-import-inventory">{{ __('Import CSV') }}</flux:button>
                    <flux:button size="sm" variant="ghost" :href="route('inventory.index', ['company' => $company->slug])" wire:navigate>{{ __('Stock on hand') }}</flux:button>
                </div>
            </div>
            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Fixed assets') }}</flux:heading>
                <flux:text>{{ __('Cost and accumulated depreciation per asset — the draft absorbs their postings automatically.') }}</flux:text>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="filled" wire:click="openExtraImport('fixed_assets')" :disabled="$obState->isFinalized()" data-test="ob-import-assets">{{ __('Import CSV') }}</flux:button>
                    <flux:button size="sm" variant="ghost" :href="route('assets.index', ['company' => $company->slug])" wire:navigate>{{ __('Fixed assets') }}</flux:button>
                </div>
            </div>
            <div class="space-y-2">
                <flux:heading size="sm">{{ __('Payroll year-to-date') }}</flux:heading>
                @if ($this->payrollYtd)
                    <flux:text data-test="ob-payroll-ytd">
                        {{ __(':with of :total employees have YTD opening figures.', ['with' => $this->payrollYtd['with_opening'], 'total' => $this->payrollYtd['total']]) }}
                    </flux:text>
                    <flux:button size="sm" variant="ghost" :href="route('payroll.employees.index', ['company' => $company->slug])" wire:navigate>{{ __('Employee setup') }}</flux:button>
                @else
                    <flux:text>{{ __('Payroll is not enabled for this company.') }}</flux:text>
                @endif
            </div>
        </div>
    </flux:card>

    <x-csv-import-modal
        name="ob-extra-import"
        :template-url="route('opening-balances.template', ['company' => $company->slug, 'step' => $extraImportKind === 'fixed_assets' ? 'fixed_assets' : 'inventory'])"
        :subtitle="$extraImportKind === 'fixed_assets'
            ? __('Fixed assets with cost and accumulated depreciation, posted at the as-of date.')
            : __('Inventory quantities on hand at the as-of date.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    />
</section>
