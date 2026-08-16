<?php

use App\Enums\DataMigrationMode;
use App\Jobs\ReplayGeneralLedgerImport;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Services\Migration\Csv\StreamingGeneralLedgerReader;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Migration\Importers\ChartOfAccountsImporter;
use App\Services\Migration\Importers\CustomersImporter;
use App\Services\Migration\Importers\FixedAssetsImporter;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use App\Services\Migration\Importers\Importer;
use App\Services\Migration\Importers\InventoryOpeningBalanceImporter;
use App\Services\Migration\Importers\ItemsImporter;
use App\Services\Migration\Importers\OpenBillsImporter;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Migration\Importers\VendorsImporter;
use App\Services\Migration\QuickBooksMigrationService;
use App\Services\Migration\SystemAccountMapper;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import from QuickBooks')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public DataMigrationRun $run;

    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $upload = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Multiple files for the general ledger replay. */
    public array $glFiles = [];

    /** Optional QuickBooks Account Listing CSV used to type accounts the CSV replay auto-creates. */
    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $accountTypesFile = null;

    public string $conversionDate = '';

    public string $historyStartDate = '';

    public bool $openInvoicesUseOriginalDate = true;

    public bool $openBillsUseOriginalDate = true;

    public string $sourceFormat = 'csv';

    public bool $autoCreateAccounts = false;

    public bool $linkContactNames = true;

    public bool $reconstructDocuments = false;

    public bool $lockBooksOnFinalize = false;

    public bool $generalLedgerRunning = false;

    /** Rendered as a nested child of the setup wizard rather than a standalone page. */
    public bool $embedded = false;

    /** Mode chosen upstream by the setup wizard; used instead of the query string when embedded. */
    public ?string $presetMode = null;

    /** @var array<string, ?int> Control-account mapping: role key => chosen account id. */
    public array $controlMapping = [];

    /** @var array<int, array<string, mixed>>|null */
    public ?array $previewRows = null;

    /** @var array<int, array{row:int,message:string}> */
    public array $previewErrors = [];

    /** @var array<string, mixed> */
    public array $previewSummary = [];

    public function mount(Company $company, QuickBooksMigrationService $service, bool $embedded = false, ?string $presetMode = null): void
    {
        // Defence in depth: this component is normally reached under the {company}
        // route middleware, but when embedded (setup wizard) it is handed a Company
        // by the parent. Never trust a parent-supplied model — a caller who is not a
        // member of $company must not start/resume a migration or read its mapping.
        abort_unless(Auth::user()?->belongsToCompany($company), 403);

        $this->company = $company;
        $this->embedded = $embedded;
        $this->presetMode = $presetMode;

        $requested = $embedded ? $presetMode : request()->query('mode');
        $mode = match ($requested) {
            DataMigrationMode::FullHistory->value => DataMigrationMode::FullHistory,
            DataMigrationMode::OpeningBalance->value => DataMigrationMode::OpeningBalance,
            default => null,
        };

        $this->run = $service->startOrResume($company, mode: $mode);

        // Repair only a genuinely out-of-range step pointer (e.g. a run saved
        // under a longer step map). In-range numbers stay authoritative; the
        // step map only ever grew, so completion (keyed by step KEY) is safe.
        if ((int) $this->run->current_step > $this->run->lastStep()) {
            $this->run->forceFill(['current_step' => $this->run->resolveCurrentStepByKey()])->save();
        }

        $this->hydrateFromRun();
    }

    /**
     * Reassign the run and bust every #[Computed] memo derived from it.
     *
     * Livewire memoizes computed values for the whole request, so an action that
     * reads e.g. $this->stepKey and then advances the step (commitUpload, skipStep
     * → goToStep) would re-render the new step's heading but the old step's body —
     * the general_ledger bulk upload silently falls back to the generic single-file
     * branch until a full page reload recomputes stepKey.
     */
    protected function setRun(DataMigrationRun $run): void
    {
        $this->run = $run;
        unset($this->stepKey, $this->steps, $this->isFullHistory, $this->generalLedgerProgress, $this->templateUrl);
    }

    protected function hydrateFromRun(): void
    {
        $this->conversionDate = $this->run->conversion_date->toDateString();
        $this->historyStartDate = $this->run->history_start_date?->toDateString() ?? '';
        $this->openInvoicesUseOriginalDate = $this->run->open_invoices_use_original_date;
        $this->openBillsUseOriginalDate = $this->run->open_bills_use_original_date;
        $this->autoCreateAccounts = (bool) $this->run->auto_create_accounts;
        $this->linkContactNames = (bool) $this->run->link_contact_names;
        $this->reconstructDocuments = (bool) $this->run->reconstruct_documents;

        $this->controlMapping = [];
        foreach (app(SystemAccountMapper::class)->currentMapping($this->company) as $key => $account) {
            $this->controlMapping[$key] = $account?->id;
        }
    }

    #[Computed]
    public function isFullHistory(): bool
    {
        return $this->run->modeEnum() === DataMigrationMode::FullHistory;
    }

    #[Computed]
    public function stepKey(): string
    {
        return $this->run->stepKey();
    }

    #[Computed]
    public function steps(): array
    {
        $labels = [
            'setup' => ['label' => 'Setup', 'desc' => $this->isFullHistory ? __('Choose how accounts and names are matched.') : __('Set the conversion date and date strategy.')],
            'chart_of_accounts' => ['label' => 'Chart of accounts', 'desc' => __('Optional — add custom accounts on top of the seeded chart.')],
            'confirm_control_accounts' => ['label' => 'Control accounts', 'desc' => __('Confirm which accounts power invoices, bills, tax, and reports.')],
            'customers' => ['label' => 'Customers', 'desc' => __('Import your QuickBooks customer list.')],
            'vendors' => ['label' => 'Vendors', 'desc' => __('Import your vendor list.')],
            'items' => ['label' => 'Items', 'desc' => __('Optional — import service & inventory items.')],
            'general_ledger' => ['label' => 'Transaction history', 'desc' => __('Replay every QuickBooks transaction into the general ledger.')],
            'open_invoices' => ['label' => 'Open invoices', 'desc' => __('One row per unpaid customer invoice — gives you live AR aging.')],
            'open_bills' => ['label' => 'Open bills', 'desc' => __('One row per unpaid vendor bill — gives you live AP aging.')],
            'inventory_opening_balance' => ['label' => 'Inventory on hand', 'desc' => __('Quantities and costs per tracked item.')],
            'fixed_assets' => ['label' => 'Fixed assets', 'desc' => __('Asset register with cost & accumulated depreciation.')],
            'trial_balance' => ['label' => 'Trial balance', 'desc' => __('Final GL trial balance for remaining accounts.')],
            'review' => ['label' => 'Review & finish', 'desc' => __('Confirm totals and finish.')],
        ];

        $steps = [];
        foreach ($this->run->steps() as $num => $key) {
            $steps[$num] = array_merge(['key' => $key], $labels[$key] ?? ['label' => $key, 'desc' => '']);
        }

        return $steps;
    }

    public function switchMode(string $mode): void
    {
        $service = app(QuickBooksMigrationService::class);
        $modeEnum = $mode === DataMigrationMode::FullHistory->value
            ? DataMigrationMode::FullHistory
            : DataMigrationMode::OpeningBalance;

        $this->setRun($service->startOrResume($this->company, mode: $modeEnum));

        if ($this->run->modeEnum() !== $modeEnum) {
            Flux::toast(variant: 'warning', text: __('This migration already has imported data — finish or abandon it before switching modes.'));

            return;
        }

        $this->hydrateFromRun();
        $this->resetPreview();
        Flux::toast(variant: 'success', text: __('Switched to :mode.', ['mode' => $modeEnum->label()]));
    }

    public function saveSetup(): void
    {
        if ($this->isFullHistory) {
            $this->validate(['historyStartDate' => ['nullable', 'date']]);

            $this->run->forceFill([
                'history_start_date' => $this->historyStartDate ? CarbonImmutable::parse($this->historyStartDate) : null,
                'auto_create_accounts' => (bool) $this->autoCreateAccounts,
                'link_contact_names' => (bool) $this->linkContactNames,
                'reconstruct_documents' => (bool) $this->reconstructDocuments,
            ])->save();
        } else {
            $this->validate(['conversionDate' => ['required', 'date']]);

            $this->run->forceFill([
                'conversion_date' => CarbonImmutable::parse($this->conversionDate),
                'open_invoices_use_original_date' => (bool) $this->openInvoicesUseOriginalDate,
                'open_bills_use_original_date' => (bool) $this->openBillsUseOriginalDate,
            ])->save();
        }

        $this->setRun($this->run->fresh());
        $this->markStepComplete('setup');
        $this->goToStep(2);

        Flux::toast(variant: 'success', text: __('Setup saved.'));
    }

    public function jumpTo(int $step): void
    {
        $this->goToStep($step);
    }

    /**
     * Account options for the "confirm control accounts" step, shaped for the
     * view (id/code/name only — never hold full models in component state).
     *
     * @return list<array{key: string, label: string, description: string, options: list<array{id: int, code: string, name: string}>}>
     */
    #[Computed]
    public function controlAccountChoices(): array
    {
        $mapper = app(SystemAccountMapper::class);
        $candidates = $mapper->candidates($this->company);

        $choices = [];
        foreach ($mapper->roles() as $role) {
            $choices[] = [
                'key' => $role->key,
                'label' => $role->label,
                'description' => $role->description,
                'options' => ($candidates[$role->key] ?? collect())
                    ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])
                    ->all(),
            ];
        }

        return $choices;
    }

    public function saveControlAccounts(): void
    {
        $mapping = array_filter(
            $this->controlMapping,
            fn ($id) => $id !== null && $id !== '',
        );

        try {
            app(SystemAccountMapper::class)->commit($this->company, $mapping);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->markStepComplete('confirm_control_accounts');
        $this->goToStep((int) $this->run->current_step + 1);

        Flux::toast(variant: 'success', text: __('Control accounts confirmed.'));
    }

    /**
     * Embedded onboarding exit: leave the in-progress import for later. The
     * company is already usable with its minimal seeded chart.
     */
    public function finishLater(): void
    {
        $this->redirectRoute('dashboard', ['company' => $this->company->slug], navigate: true);
    }

    public function skipStep(): void
    {
        $this->markStepComplete($this->stepKey, skipped: true);
        $this->goToStep((int) $this->run->current_step + 1);
        Flux::toast(text: __('Step skipped.'));
    }

    public function previewUpload(): void
    {
        if (! $this->hasFilesForStep()) {
            Flux::toast(variant: 'warning', text: __('Choose a file first.'));

            return;
        }

        try {
            $result = $this->runImporter(dryRun: true);
        } catch (\Throwable $e) {
            $this->previewRows = [];
            $this->previewErrors = [['row' => 0, 'message' => $e->getMessage()]];
            $this->previewSummary = [];

            return;
        }

        // Cap what we hold in component state — Livewire serialises it on every
        // request and a few thousand rows would blow past its payload limit.
        $this->previewRows = array_slice($result->previewRows, 0, 200);
        $this->previewErrors = array_slice($result->errors, 0, 100);
        $this->previewSummary = $result->summary;
    }

    public function commitUpload(): void
    {
        if (! $this->hasFilesForStep()) {
            Flux::toast(variant: 'warning', text: __('Choose a file first.'));

            return;
        }

        if ($this->stepKey === 'general_ledger') {
            $this->commitGeneralLedger();

            return;
        }

        try {
            $result = $this->runImporter(dryRun: false);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Import failed: ').$e->getMessage());

            return;
        }

        // Cap what we hold in component state — Livewire serialises it on every
        // request and a few thousand rows would blow past its payload limit.
        $this->previewRows = array_slice($result->previewRows, 0, 200);
        $this->previewErrors = array_slice($result->errors, 0, 100);
        $this->previewSummary = $result->summary;

        if ($result->hasErrors()) {
            Flux::toast(variant: 'danger', text: __('Import has errors — nothing was saved.'));

            return;
        }

        $this->markStepComplete($this->stepKey, $result->summary);
        $this->resetPreview();
        $this->previewSummary = $result->summary;
        $this->goToStep((int) $this->run->current_step + 1);

        Flux::toast(variant: 'success', text: __('Import committed.'));
    }

    /**
     * The general ledger replay can be large, so it runs as a queued job; the UI
     * polls step_results for progress and advances when it completes.
     */
    protected function commitGeneralLedger(): void
    {
        $this->validate([
            'glFiles' => ['required', 'array', 'min:1'],
            'glFiles.*' => ['file', 'max:102400'], // up to 100 MB each
            'accountTypesFile' => ['nullable', 'file', 'max:10240'],
        ]);

        // Persist each upload to the local disk; the queued job reads then deletes them.
        $storedPaths = [];
        foreach ($this->glFiles as $file) {
            $storedPaths[] = $file->store('migrations', 'local');
        }

        // Optional Account Listing — used only on CSV replays to type auto-created accounts.
        $accountTypesPath = null;
        if ($this->sourceFormat !== StreamingGeneralLedgerReader::FORMAT_IIF
            && $this->autoCreateAccounts
            && $this->accountTypesFile !== null) {
            $accountTypesPath = $this->accountTypesFile->store('migrations', 'local');
        }

        // Seed a running marker so the poller shows progress immediately.
        $results = $this->run->step_results ?? [];
        $results['general_ledger'] = ['status' => 'running', 'progress' => 0, 'files' => count($storedPaths)];
        $this->run->forceFill(['step_results' => $results])->save();
        $this->setRun($this->run->fresh());

        ReplayGeneralLedgerImport::dispatch(
            companyId: (int) $this->company->id,
            runId: (int) $this->run->id,
            storedPaths: $storedPaths,
            sourceFormat: $this->sourceFormat === StreamingGeneralLedgerReader::FORMAT_IIF ? StreamingGeneralLedgerReader::FORMAT_IIF : StreamingGeneralLedgerReader::FORMAT_CSV,
            autoCreateAccounts: (bool) $this->autoCreateAccounts,
            linkContactNames: (bool) $this->linkContactNames,
            reconstructDocuments: (bool) $this->reconstructDocuments,
            accountTypesPath: $accountTypesPath,
        );

        $this->generalLedgerRunning = true;
        $this->glFiles = [];
        $this->accountTypesFile = null;
        $this->resetPreview();

        Flux::toast(text: trans_choice('Replaying transaction history from :count file…|Replaying transaction history from :count files…', count($storedPaths), ['count' => count($storedPaths)]));
    }

    protected function hasFilesForStep(): bool
    {
        return $this->stepKey === 'general_ledger' ? $this->glFiles !== [] : (bool) $this->upload;
    }

    /**
     * Polled while the GL replay runs. Picks up progress and advances on completion.
     */
    public function tickGeneralLedger(): void
    {
        $this->setRun($this->run->fresh());

        if ($this->run->isStepComplete('general_ledger')) {
            $this->generalLedgerRunning = false;
            $this->previewSummary = $this->run->step_results['general_ledger'] ?? [];
            $this->goToStep((int) $this->run->current_step + 1);
            Flux::toast(variant: 'success', text: __('Transaction history imported.'));

            return;
        }

        $status = $this->run->step_results['general_ledger']['status'] ?? null;

        if ($status === 'failed') {
            $this->generalLedgerRunning = false;
            $this->previewErrors = $this->run->step_results['general_ledger']['errors'] ?? [['row' => 0, 'message' => $this->run->step_results['general_ledger']['message'] ?? __('Import failed.')]];
        }
    }

    #[Computed]
    public function generalLedgerProgress(): ?array
    {
        return $this->run->step_results['general_ledger'] ?? null;
    }

    public function finalize(QuickBooksMigrationService $service): void
    {
        $service->finalize($this->run, lockBooks: $this->isFullHistory && $this->lockBooksOnFinalize);
        $this->setRun($this->run->fresh());
        Flux::toast(variant: 'success', text: __('Conversion complete.'));
        $this->redirectRoute('dashboard', ['company' => $this->company->slug], navigate: true);
    }

    public function abandon(QuickBooksMigrationService $service): void
    {
        $service->abandon($this->run);
        $this->redirectRoute('dashboard', ['company' => $this->company->slug], navigate: true);
    }

    protected function runImporter(bool $dryRun): ImportResult
    {
        $importer = $this->resolveImporter();
        $ctx = $this->context();
        $method = $dryRun ? 'preview' : 'commit';

        $paths = $this->stepKey === 'general_ledger'
            ? array_map(fn ($file) => $file->getRealPath(), $this->glFiles)
            : $this->upload->getRealPath();

        return $importer->{$method}($paths, $ctx);
    }

    protected function resolveImporter(): Importer
    {
        return match ($this->stepKey) {
            'chart_of_accounts' => app(ChartOfAccountsImporter::class),
            'customers' => app(CustomersImporter::class),
            'vendors' => app(VendorsImporter::class),
            'items' => app(ItemsImporter::class),
            'general_ledger' => app(GeneralLedgerReplayImporter::class),
            'open_invoices' => app(OpenInvoicesImporter::class),
            'open_bills' => app(OpenBillsImporter::class),
            'inventory_opening_balance' => app(InventoryOpeningBalanceImporter::class),
            'fixed_assets' => app(FixedAssetsImporter::class),
            'trial_balance' => app(TrialBalanceImporter::class),
            default => throw new \RuntimeException("No importer for step '{$this->stepKey}'."),
        };
    }

    protected function context(): ImportContext
    {
        $useOriginal = match ($this->stepKey) {
            'open_invoices' => $this->run->open_invoices_use_original_date,
            'open_bills' => $this->run->open_bills_use_original_date,
            default => true,
        };

        return new ImportContext(
            company: $this->company,
            run: $this->run,
            conversionDate: CarbonImmutable::parse($this->run->conversion_date),
            useOriginalDates: $useOriginal,
            sourceFormat: $this->sourceFormat === StreamingGeneralLedgerReader::FORMAT_IIF ? StreamingGeneralLedgerReader::FORMAT_IIF : StreamingGeneralLedgerReader::FORMAT_CSV,
            autoCreateAccounts: (bool) $this->autoCreateAccounts,
            linkContactNames: (bool) $this->linkContactNames,
            reconstructDocuments: (bool) $this->reconstructDocuments,
        );
    }

    protected function markStepComplete(string $stepKey, array $payload = [], bool $skipped = false): void
    {
        $this->run->recordStepResult($stepKey, array_merge($payload, ['skipped' => $skipped]));
        $this->setRun($this->run->fresh());
    }

    protected function goToStep(int $step): void
    {
        $clamped = max(1, min($step, $this->run->lastStep()));
        $this->run->forceFill(['current_step' => $clamped])->save();
        $this->setRun($this->run->fresh());
        $this->resetPreview();
    }

    protected function resetPreview(): void
    {
        $this->upload = null;
        $this->glFiles = [];
        $this->previewRows = null;
        $this->previewErrors = [];
        $this->previewSummary = [];
    }

    #[Computed]
    public function templateUrl(): string
    {
        return route('migration.template', ['company' => $this->company->slug, 'step' => $this->stepKey]);
    }
}; ?>

<section class="{{ $this->embedded ? 'w-full' : 'w-full p-6' }}">
    @unless ($this->embedded)
        <flux:heading size="xl" level="1">{{ __('Import from QuickBooks') }}</flux:heading>
        <flux:text class="mb-4">
            {{ __('Bring your QuickBooks company into LineLedger. Choose how much you want to import below.') }}
        </flux:text>

        {{-- Mode switch --}}
        <div class="mb-6 flex flex-wrap gap-2">
            <flux:button size="sm" wire:click="switchMode('opening_balance')"
                :variant="$this->isFullHistory ? 'ghost' : 'primary'">
                {{ __('Opening balances') }}
            </flux:button>
            <flux:button size="sm" wire:click="switchMode('full_history')"
                :variant="$this->isFullHistory ? 'primary' : 'ghost'">
                {{ __('Full transaction history') }}
            </flux:button>
            <flux:text class="self-center text-xs text-muted-foreground">
                {{ $this->isFullHistory
                    ? __('Replays every historical transaction (QuickBooks Desktop Journal CSV).')
                    : __('Standard conversion: balances as of a conversion date, then lock.') }}
            </flux:text>
        </div>
    @endunless

    <div class="flex gap-6">
        <aside class="w-72 shrink-0">
            <ol class="rounded-lg border border-border bg-card p-3">
                @foreach ($this->steps as $num => $step)
                    @php
                        $isCurrent = (int) $this->run->current_step === $num;
                        $isDone = $this->run->isStepComplete($step['key']);
                    @endphp
                    <li>
                        <button
                            type="button"
                            wire:click="jumpTo({{ $num }})"
                            class="flex w-full items-start gap-3 rounded-md px-3 py-2 text-left text-sm transition
                                {{ $isCurrent ? 'bg-muted font-semibold' : 'hover:bg-muted' }}"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs
                                {{ $isDone ? 'border-emerald-600 bg-emerald-600 text-white' : ($isCurrent ? 'border-foreground' : 'border-border') }}">
                                {{ $isDone ? '✓' : $num }}
                            </span>
                            <span>{{ $step['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <div class="mt-4 flex flex-col gap-2">
                @if ($this->embedded)
                    <flux:button variant="ghost" size="sm" wire:click="finishLater">
                        {{ __('Finish later') }}
                    </flux:button>
                @else
                    <flux:button variant="ghost" size="sm" wire:click="abandon" wire:confirm="{{ $this->isFullHistory ? __('Abandon this migration? Replayed transaction history will be removed.') : __('Abandon this migration? Already-imported data stays in the company.') }}">
                        {{ __('Abandon migration') }}
                    </flux:button>
                @endif
            </div>
        </aside>

        <main class="flex-1">
            @php $current = $this->steps[(int) $this->run->current_step]; @endphp

            <div class="rounded-lg border border-border bg-card p-6">
                <flux:heading size="lg">{{ $current['label'] }}</flux:heading>
                <flux:text class="mb-4">{{ $current['desc'] }}</flux:text>

                @if ($this->stepKey === 'setup' && $this->isFullHistory)
                    <form wire:submit="saveSetup" class="space-y-4 max-w-xl">
                        <flux:input
                            type="date"
                            wire:model="historyStartDate"
                            :label="__('History start date (optional)')"
                            :description="__('The earliest transaction date you are bringing over. Used only if you choose to lock pre-go-live data at the end.')"
                        />

                        <flux:checkbox wire:model="autoCreateAccounts" :label="__('Auto-create accounts found in the file but not in the chart')" />
                        <flux:text class="text-xs text-muted-foreground -mt-3">
                            {{ __('IIF files include account types. For Journal CSVs, attach a QuickBooks Account Listing on the Transaction history step to type auto-created accounts; otherwise they default to Other Asset and should be reviewed. Leave off to require a matching chart first.') }}
                        </flux:text>

                        <flux:checkbox wire:model="linkContactNames" :label="__('Link transaction names to customers/vendors')" />

                        <flux:checkbox wire:model="reconstructDocuments" :label="__('Reconstruct documents (invoices, bills, cheques, deposits, receipts) where possible')" />
                        <flux:text class="text-xs text-muted-foreground -mt-3">
                            {{ __('Recognised transaction types become real documents with account-level lines; everything else stays a journal entry. Receipts/payments are auto-applied oldest-first. Requires customers/vendors imported first so names can match.') }}
                        </flux:text>

                        <div class="pt-2">
                            <flux:button type="submit" variant="primary">{{ __('Save & continue') }}</flux:button>
                        </div>
                    </form>
                @elseif ($this->stepKey === 'setup')
                    <form wire:submit="saveSetup" class="space-y-4 max-w-xl">
                        <flux:input
                            type="date"
                            wire:model="conversionDate"
                            :label="__('Conversion date')"
                            :description="__('Usually your QuickBooks fiscal year-end. Pre-conversion entries will be locked once you finish.')"
                        />

                        <flux:checkbox wire:model="openInvoicesUseOriginalDate" :label="__('Use original invoice dates when importing open AR invoices')" />
                        <flux:text class="text-xs text-muted-foreground -mt-3">
                            {{ __('Keep original dates so AR aging buckets stay accurate. Untick to date all imports as the conversion date.') }}
                        </flux:text>

                        <flux:checkbox wire:model="openBillsUseOriginalDate" :label="__('Use original bill dates when importing open AP bills')" />

                        <div class="pt-2">
                            <flux:button type="submit" variant="primary">{{ __('Save & continue') }}</flux:button>
                        </div>
                    </form>
                @elseif ($this->stepKey === 'confirm_control_accounts')
                    <div class="space-y-5 max-w-2xl">
                        <flux:callout icon="information-circle">
                            <flux:callout.heading>{{ __('These accounts power your books') }}</flux:callout.heading>
                            <flux:callout.text>
                                {{ __('We seeded standard control accounts so everything already works. If QuickBooks brought over your own versions, point each role at the right account — otherwise just continue.') }}
                            </flux:callout.text>
                        </flux:callout>

                        @foreach ($this->controlAccountChoices as $choice)
                            <flux:select
                                wire:model="controlMapping.{{ $choice['key'] }}"
                                :label="$choice['label']"
                                :description="$choice['description']"
                            >
                                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                                @foreach ($choice['options'] as $option)
                                    <flux:select.option value="{{ $option['id'] }}">{{ $option['code'] }} — {{ $option['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach

                        <div class="flex items-center gap-2 pt-2">
                            <flux:button variant="primary" wire:click="saveControlAccounts">{{ __('Save & continue') }}</flux:button>
                            <flux:button variant="ghost" wire:click="skipStep">{{ __('Keep defaults') }}</flux:button>
                        </div>
                    </div>
                @elseif ($this->stepKey === 'review')
                    <div class="space-y-4 max-w-2xl">
                        <flux:callout variant="success" icon="check-circle">
                            <flux:callout.heading>{{ __('All import steps complete') }}</flux:callout.heading>
                            <flux:callout.text>
                                {{ __('Open the Trial Balance and aging reports and confirm the totals match your QuickBooks reports. If anything is off, jump back to the relevant step.') }}
                            </flux:callout.text>
                        </flux:callout>

                        @if ($this->isFullHistory)
                            @php
                                $gl = $this->run->step_results['general_ledger'] ?? [];
                                $glAr = $gl['ar_balance_cents'] ?? null;
                                $glAp = $gl['ap_balance_cents'] ?? null;
                                $openAr = $this->run->step_results['open_invoices']['total_ar_cents'] ?? null;
                                $openAp = $this->run->step_results['open_bills']['total_ap_cents'] ?? null;
                            @endphp
                            <div class="rounded-md border border-border p-4 text-sm">
                                <p class="font-medium mb-2">{{ __('AR / AP reconciliation') }}</p>
                                <ul class="space-y-1 text-muted-foreground">
                                    <li>{{ __('Replayed AR balance:') }} {{ $glAr === null ? '—' : \App\Services\Migration\Csv\CsvParser::centsLabel((int) $glAr) }}
                                        @if ($openAr !== null) · {{ __('Open invoices total:') }} {{ \App\Services\Migration\Csv\CsvParser::centsLabel((int) $openAr) }} @endif
                                    </li>
                                    <li>{{ __('Replayed AP balance:') }} {{ $glAp === null ? '—' : \App\Services\Migration\Csv\CsvParser::centsLabel((int) $glAp) }}
                                        @if ($openAp !== null) · {{ __('Open bills total:') }} {{ \App\Services\Migration\Csv\CsvParser::centsLabel((int) $openAp) }} @endif
                                    </li>
                                </ul>
                                <flux:text class="mt-2 text-xs text-muted-foreground">
                                    {{ __('If you imported open invoices/bills as documents, exclude those same transactions from the GL file so AR/AP is not double-counted.') }}
                                </flux:text>
                            </div>
                        @endif

                        @php $asOf = $this->run->conversion_date->toDateString(); @endphp
                        <ul class="space-y-2 text-sm">
                            <li><a class="underline" href="{{ route('reports.trial-balance', ['company' => $this->company->slug, 'as_of' => $asOf]) }}" target="_blank">{{ __('Trial Balance →') }}</a></li>
                            <li><a class="underline" href="{{ route('reports.ar-aging', ['company' => $this->company->slug, 'as_of' => $asOf]) }}" target="_blank">{{ __('AR Aging →') }}</a></li>
                            <li><a class="underline" href="{{ route('reports.ap-aging', ['company' => $this->company->slug, 'as_of' => $asOf]) }}" target="_blank">{{ __('AP Aging →') }}</a></li>
                        </ul>

                        @if ($this->isFullHistory)
                            <flux:checkbox wire:model="lockBooksOnFinalize" :label="__('Lock everything on or before the history start date')" />
                            <flux:text class="text-xs text-muted-foreground -mt-2">
                                {{ __('Optional. Leave unticked to keep the books fully open after import.') }}
                            </flux:text>
                            <flux:button variant="primary" wire:click="finalize" wire:confirm="{{ __('Finish the import?') }}">
                                {{ __('Finish import') }}
                            </flux:button>
                        @else
                            <flux:callout variant="warning" icon="lock-closed">
                                <flux:callout.heading>{{ __('Finalize will lock the books') }}</flux:callout.heading>
                                <flux:callout.text>
                                    {{ __('Clicking finalize sets the lock date to') }} <strong>{{ $asOf }}</strong>. {{ __('No new postings dated on or before that date will be accepted.') }}
                                </flux:callout.text>
                            </flux:callout>
                            <flux:button variant="primary" wire:click="finalize" wire:confirm="{{ __('Finalize migration and lock the books at the conversion date?') }}">
                                {{ __('Finalize & lock') }}
                            </flux:button>
                        @endif
                    </div>
                @elseif ($this->stepKey === 'general_ledger' && ($this->generalLedgerRunning || (($this->generalLedgerProgress['status'] ?? null) === 'running')))
                    <div class="space-y-4" wire:poll.2s="tickGeneralLedger">
                        <flux:callout icon="arrow-path">
                            <flux:callout.heading>{{ __('Replaying transaction history…') }}</flux:callout.heading>
                            <flux:callout.text>
                                {{ __(':n transactions posted so far. This page updates automatically.', ['n' => $this->generalLedgerProgress['progress'] ?? 0]) }}
                            </flux:callout.text>
                        </flux:callout>
                        <flux:text class="text-xs text-muted-foreground">{{ __('Large files keep running even if you navigate away — a queue worker must be running.') }}</flux:text>
                    </div>
                @else
                    <div class="space-y-4">
                        @if ($this->stepKey === 'general_ledger')
                            @php
                                $chartStep = $this->run->step_results['chart_of_accounts'] ?? null;
                                $chartImported = $chartStep !== null && empty($chartStep['skipped']);
                            @endphp
                            @if ($this->reconstructDocuments && ! $chartImported)
                                <flux:callout variant="warning" icon="exclamation-triangle">
                                    <flux:callout.heading>{{ __('Import your chart of accounts first') }}</flux:callout.heading>
                                    <flux:callout.text>
                                        {{ __('Document reconstruction is on, but the Chart of accounts step was skipped. Without it, accounts are created untyped (Other Asset) and invoices, bills, receipts, cheques and deposits will fall back to plain journal entries. Go back to the Chart of accounts step and import the QuickBooks Account Listing (it carries the account types), or turn off document reconstruction in Setup.') }}
                                    </flux:callout.text>
                                </flux:callout>
                            @endif

                            <flux:callout icon="information-circle">
                                <flux:callout.heading>{{ __('Use the Journal report — not the General Ledger report') }}</flux:callout.heading>
                                <flux:callout.text>
                                    {{ __('In QuickBooks Desktop: Reports → Accountant & Taxes → Journal. Set the date range to All, then Export → CSV. The Journal report lists each transaction with its split lines and Debit/Credit columns. (Adding the "Trans #" column makes grouping exact, but is optional.)') }}
                                    <br><br>
                                    {{ __('The General Ledger report is organised by account with a single signed Amount column and cannot be imported directly. A native IIF file also works.') }}
                                </flux:callout.text>
                            </flux:callout>

                            <flux:radio.group wire:model.live="sourceFormat" :label="__('Source format')" variant="segmented">
                                <flux:radio value="csv">{{ __('Journal CSV') }}</flux:radio>
                                <flux:radio value="iif">{{ __('IIF file') }}</flux:radio>
                            </flux:radio.group>

                            @if ($this->sourceFormat === 'csv' && $this->autoCreateAccounts)
                                <div class="rounded-md border border-border p-4">
                                    <label class="block text-sm font-medium">{{ __('Account types (optional)') }}</label>
                                    <p class="text-xs text-muted-foreground mt-1 mb-2">
                                        {{ __('Journal CSVs carry no account types, so auto-created accounts default to Other Asset. Attach a QuickBooks Account Listing (Reports → Lists → Account Listing, include inactive, export to CSV) and accounts are typed from it by number, then name.') }}
                                    </p>
                                    <input type="file" wire:model="accountTypesFile" accept=".csv,text/csv" class="block w-full text-sm" />

                                    <div wire:loading wire:target="accountTypesFile" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>

                                    @if ($this->accountTypesFile !== null)
                                        <p class="mt-2 text-sm text-emerald-600">{{ __('Account Listing ready — auto-created accounts will be typed from it.') }}</p>
                                    @endif

                                    @error('accountTypesFile')
                                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif
                        @endif

                        <div class="flex flex-wrap items-center gap-3">
                            <flux:button as="a" :href="$this->templateUrl" target="_blank" variant="ghost" icon="arrow-down-tray">
                                {{ __('Download template') }}
                            </flux:button>
                        </div>

                        @php $hasFiles = $this->stepKey === 'general_ledger' ? count($glFiles) > 0 : (bool) $upload; @endphp

                        <div class="rounded-md border border-border p-4">
                            @if ($this->stepKey === 'general_ledger')
                                <div
                                    x-data="{ dragging: false }"
                                    x-on:dragover.prevent="dragging = true"
                                    x-on:dragleave.prevent="dragging = false"
                                    x-on:drop.prevent="dragging = false; $wire.uploadMultiple('glFiles', $event.dataTransfer.files, () => {}, () => {})"
                                    x-on:click="$refs.glInput.click()"
                                    :class="dragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40' : 'border-border'"
                                    class="cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition"
                                >
                                    <input type="file" multiple accept=".csv,.iif,.txt" x-ref="glInput" class="hidden"
                                        x-on:change="$wire.uploadMultiple('glFiles', $event.target.files, () => {}, () => {})" />
                                    <flux:icon.arrow-up-tray class="mx-auto mb-2 size-6 text-muted-foreground" />
                                    <p class="text-sm">{{ __('Drag & drop your Journal CSV / IIF files here, or click to choose.') }}</p>
                                    <p class="text-xs text-muted-foreground mt-1">{{ __('Select all of them at once — they import together, in date order. Up to 100 MB each.') }}</p>
                                </div>

                                <div wire:loading wire:target="glFiles" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>

                                @if (count($glFiles) > 0)
                                    <p class="mt-2 text-sm text-emerald-600">{{ trans_choice(':count file ready to import.|:count files ready to import.', count($glFiles), ['count' => count($glFiles)]) }}</p>
                                @endif

                                @error('glFiles.*')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                            @else
                                <label class="block text-sm font-medium mb-2">{{ __('Upload CSV') }}</label>
                                <input type="file" wire:model="upload" accept=".csv,text/csv" class="block w-full text-sm" />

                                <div wire:loading wire:target="upload" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>

                                @error('upload')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                            @endif

                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:button wire:click="previewUpload" :disabled="! $hasFiles">
                                    {{ __('Preview') }}
                                </flux:button>
                                <flux:button variant="primary" wire:click="commitUpload" :disabled="! $hasFiles">
                                    {{ $this->stepKey === 'general_ledger' ? __('Import history') : __('Commit') }}
                                </flux:button>
                                <flux:button variant="ghost" wire:click="skipStep" wire:confirm="{{ __('Skip this step?') }}">
                                    {{ __('Skip this step') }}
                                </flux:button>
                            </div>
                        </div>

                        @if ($previewErrors !== [])
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                <flux:callout.heading>{{ __('Found :n issue(s) — fix and re-upload', ['n' => count($previewErrors)]) }}</flux:callout.heading>
                                <flux:callout.text>
                                    <ul class="list-disc pl-5">
                                        @foreach ($previewErrors as $err)
                                            <li>{{ __('Row ') }}{{ $err['row'] }}: {{ $err['message'] }}</li>
                                        @endforeach
                                    </ul>
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        @if ($previewRows !== null && $previewRows !== [])
                            <div class="rounded-md border border-border overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-muted">
                                    <tr>
                                        @foreach (array_keys($previewRows[0]) as $col)
                                            <th class="px-3 py-2 text-left font-medium">{{ $col }}</th>
                                        @endforeach
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($previewRows as $row)
                                        <tr class="border-t border-border">
                                            @foreach ($row as $val)
                                                <td class="px-3 py-2">{{ is_bool($val) ? ($val ? '✓' : '') : $val }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                @php $previewTotal = $previewSummary['transactions'] ?? $previewSummary['rows'] ?? null; @endphp
                                @if ($previewTotal !== null && $previewTotal > count($previewRows))
                                    <p class="px-3 py-2 text-xs text-muted-foreground">{{ __('Showing first :shown of :total rows.', ['shown' => count($previewRows), 'total' => $previewTotal]) }}</p>
                                @endif
                            </div>
                        @endif

                        @if ($previewSummary !== [])
                            <flux:text class="text-sm text-muted-foreground">
                                {{ collect($previewSummary)
                                    ->reject(fn ($v) => is_array($v))
                                    ->map(fn ($v, $k) => $k.': '.(is_int($v) && str_ends_with($k, '_cents') ? \App\Services\Migration\Csv\CsvParser::centsLabel($v) : $v))
                                    ->implode(' • ') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>
        </main>
    </div>
</section>
