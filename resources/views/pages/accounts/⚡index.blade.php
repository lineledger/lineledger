<?php

use App\Actions\Accounting\MergeAccounts;
use App\Actions\Accounting\PostAccountOpeningBalance;
use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Enums\JurisdictionCapability;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\GridPreference;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Rules\ValidAccountParent;
use App\Services\Migration\Importers\ChartOfAccountsImporter;
use App\Services\Reporting\ReportCalculator;
use App\Support\Gifi\GifiCatalog;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Chart of Accounts')] class extends Component {
    use WithFileUploads;

    /** Toggleable grid columns, in display order. Code + Name are always shown. */
    private const GRID_COLUMNS = ['subtype', 'description', 'gifi', 'currency', 'balance', 'id'];

    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showInactive = false;

    public ?int $editingId = null;

    public string $form_code = '';

    public string $form_name = '';

    public string $form_subtype = '';

    public ?int $form_parent_id = null;

    public string $form_description = '';

    public ?string $form_cash_flow_activity = null;

    public ?string $form_gifi_code = null;

    public ?int $form_default_tax_code_id = null;

    public ?string $form_currency_code = null;

    public bool $form_is_active = true;

    public bool $form_use_in_transfers = false;

    public string $form_opening_balance = '';

    public string $form_opening_balance_as_of = '';

    /** @var array<int, string> */
    public array $visibleColumns = ['subtype', 'balance'];

    public ?int $mergingId = null;

    public ?int $mergeTargetId = null;

    public bool $mergeConfirmed = false;

    /** Uploaded CSV for the "Import accounts" flow. */
    public mixed $importFile = null;

    /** @var ?list<array<string, mixed>> Dry-run preview rows; null until the file is previewed. */
    public ?array $importPreviewRows = null;

    /** @var list<array{row:int, message:string}> */
    public array $importErrors = [];

    /** @var array<string, mixed> */
    public array $importSummary = [];

    public function mount(Company $company): void
    {
        $this->company = $company;

        // Restore this user's saved column choices (per user + company); no
        // row means the defaults apply. Unknown values are dropped so a stale
        // row can never render a column the page no longer offers.
        $saved = auth()->id()
            ? GridPreference::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('user_id', auth()->id())
                ->where('grid_key', 'chart_of_accounts')
                ->value('visible_columns')
            : null;

        if (is_array($saved)) {
            $this->visibleColumns = array_values(array_intersect(self::GRID_COLUMNS, $saved));
        }
    }

    public function updatedVisibleColumns(): void
    {
        $this->visibleColumns = array_values(array_intersect(self::GRID_COLUMNS, $this->visibleColumns));

        if (auth()->id() === null) {
            return;
        }

        GridPreference::withoutGlobalScopes()->updateOrCreate([
            'company_id' => $this->company->id,
            'user_id' => auth()->id(),
            'grid_key' => 'chart_of_accounts',
        ], [
            'visible_columns' => $this->visibleColumns,
        ]);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->form_opening_balance_as_of = $this->company->currentDateTime()->toDateString();
        Flux::modal('account-form')->show();
    }

    public function openEdit(int $id): void
    {
        $account = Account::findOrFail($id);

        $this->editingId = $account->id;
        $this->form_code = $account->code;
        $this->form_name = $account->name;
        $this->form_subtype = $account->subtype->value;
        $this->form_parent_id = $account->parent_id;
        $this->form_description = $account->description ?? '';
        $this->form_cash_flow_activity = $account->cash_flow_activity?->value;
        $this->form_gifi_code = $account->gifi_code;
        $this->form_default_tax_code_id = $account->default_tax_code_id;
        $this->form_currency_code = $account->currency_code;
        $this->form_is_active = $account->is_active;
        $this->form_use_in_transfers = (bool) $account->use_in_transfers;
        $this->form_opening_balance = '';
        $this->form_opening_balance_as_of = '';

        Flux::modal('account-form')->show();
    }

    public function updatedFormSubtype(): void
    {
        // A parent must match the (new) account type; drop a now-mismatched parent.
        $type = AccountSubtype::tryFrom($this->form_subtype)?->type();
        if ($this->form_parent_id !== null && $type !== null) {
            $parentType = Account::find($this->form_parent_id)?->type;
            if ($parentType !== $type) {
                $this->form_parent_id = null;
            }
        }

        // The currency field only applies to Bank / Credit Card accounts.
        if (! in_array(AccountSubtype::tryFrom($this->form_subtype), [AccountSubtype::Bank, AccountSubtype::CreditCard], true)) {
            $this->form_currency_code = null;
        }

        // The default tax code only applies to Income / Expense accounts.
        if (! in_array($type, [AccountType::Income, AccountType::Expense], true)) {
            $this->form_default_tax_code_id = null;
        }
    }

    public function save(): void
    {
        $editing = $this->editingId
            ? $this->company->accounts()->findOrFail($this->editingId)
            : null;

        $uniqueCode = Rule::unique('accounts', 'code')
            ->where('company_id', $this->company->id)
            ->whereNull('deleted_at');

        if ($editing) {
            $uniqueCode->ignore($editing->id);
        }

        $validated = $this->validate([
            'form_code' => ['required', 'string', 'max:20', $uniqueCode],
            'form_name' => ['required', 'string', 'max:255'],
            'form_subtype' => ['required', 'string', Rule::enum(AccountSubtype::class)],
            'form_parent_id' => [
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->company->id),
                new ValidAccountParent($this->company->id, $this->form_subtype, $this->editingId),
            ],
            'form_description' => ['nullable', 'string'],
            'form_cash_flow_activity' => ['nullable', Rule::enum(CashFlowActivity::class)],
            'form_gifi_code' => ['nullable', Rule::in(GifiCatalog::codes())],
            'form_default_tax_code_id' => [
                'nullable', 'integer',
                Rule::exists('tax_codes', 'id')->where('company_id', $this->company->id),
            ],
            'form_currency_code' => ['nullable', 'string', Rule::in($this->currencyOptions->all())],
            'form_is_active' => ['boolean'],
            'form_use_in_transfers' => ['boolean'],
            'form_opening_balance' => [
                'nullable', 'string', new MoneyString,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Money::tryFromString((string) $value)?->isNegative()) {
                        $fail(__('The opening balance must be zero or a positive amount; use a journal entry for contra balances.'));
                    }
                },
            ],
            'form_opening_balance_as_of' => ['nullable', 'date', 'required_with:form_opening_balance'],
        ]);

        // Opening balance only applies on create, for eligible balance-sheet
        // subtypes, in home currency. Blank or zero means "no opening entry".
        $openingCents = (! $editing && $this->showOpeningBalanceFields)
            ? (Money::tryFromString((string) $validated['form_opening_balance'])?->cents ?? 0)
            : 0;

        try {
            // One transaction: if the opening-balance post is rejected (locked
            // period, missing OBE account), the account creation rolls back too.
            DB::transaction(function () use ($validated, $editing, $openingCents): void {
                $account = app(SaveAccount::class)->handle([
                    'code' => $validated['form_code'],
                    'name' => $validated['form_name'],
                    'subtype' => $validated['form_subtype'],
                    'parent_id' => $validated['form_parent_id'] ?: null,
                    'description' => $validated['form_description'] ?: null,
                    'cash_flow_activity' => $validated['form_cash_flow_activity'] ?: null,
                    // The default tax code only applies to Income / Expense accounts.
                    'default_tax_code_id' => $this->showDefaultTaxCodeField
                        ? ($validated['form_default_tax_code_id'] ?: null)
                        : null,
                    ...($this->company->supports(JurisdictionCapability::GifiCodeMapping) ? ['gifi_code' => $validated['form_gifi_code'] ?: null] : []),
                    ...($this->company->isMulticurrencyEnabled() ? ['currency_code' => $validated['form_currency_code'] ?: null] : []),
                    'is_active' => $validated['form_is_active'],
                    'use_in_transfers' => $validated['form_use_in_transfers'],
                ], $editing);

                if ($openingCents > 0) {
                    app(PostAccountOpeningBalance::class)->handle(
                        $account,
                        $openingCents,
                        $validated['form_opening_balance_as_of'],
                    );
                }
            });
        } catch (PeriodLockedException $e) {
            $this->addError('form_opening_balance_as_of', $e->clientSafeMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('form_opening_balance', collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('account-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Account saved.'));
    }

    public function openImport(): void
    {
        $this->resetImport();
        Flux::modal('account-import')->show();
    }

    /** Re-previewing is required whenever the chosen file changes. */
    public function updatedImportFile(): void
    {
        $this->importPreviewRows = null;
        $this->importErrors = [];
        $this->importSummary = [];
    }

    public function previewImport(): void
    {
        $this->validate($this->importRules());

        $this->applyImportResult(
            app(ChartOfAccountsImporter::class)->previewForCompany($this->importFile->getRealPath(), $this->company),
        );
    }

    public function runImport(): void
    {
        $this->validate($this->importRules());

        $importer = app(ChartOfAccountsImporter::class);

        // Re-validate the file before writing and refuse a partial import: if any
        // row is invalid we surface the errors and create nothing.
        $preview = $importer->previewForCompany($this->importFile->getRealPath(), $this->company);

        if ($preview->hasErrors()) {
            $this->applyImportResult($preview);
            Flux::toast(variant: 'danger', text: __('Fix the errors below before importing.'));

            return;
        }

        $result = $importer->commitForCompany($this->importFile->getRealPath(), $this->company);
        $this->applyImportResult($result);

        // The commit runs in one transaction: any failure rolls back every row, so
        // a result with errors means nothing was saved — don't claim success.
        if ($result->hasErrors()) {
            Flux::toast(variant: 'danger', text: __('Import failed — no accounts were saved. See the errors below.'));

            return;
        }

        Flux::modal('account-import')->close();
        $this->resetImport();

        Flux::toast(variant: 'success', text: trans_choice('{0}No new accounts to import.|{1}:count account imported.|[2,*]:count accounts imported.', $result->summary['created'] ?? 0, ['count' => $result->summary['created'] ?? 0]));
    }

    private function applyImportResult(\App\Services\Migration\ImportResult $result): void
    {
        $this->importPreviewRows = $result->previewRows;
        $this->importErrors = $result->errors;
        $this->importSummary = $result->summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function importRules(): array
    {
        return [
            'importFile' => ['required', 'file', \Illuminate\Validation\Rules\File::types(['csv', 'txt'])->max(2048)],
        ];
    }

    private function resetImport(): void
    {
        $this->reset(['importFile', 'importPreviewRows', 'importErrors', 'importSummary']);
    }

    public function toggleActive(int $id): void
    {
        $account = Account::findOrFail($id);
        abort_unless($account->company_id === $this->company->id, 403);
        $account->update(['is_active' => ! $account->is_active]);

        Flux::toast(variant: 'success', text: $account->is_active ? __('Account activated.') : __('Account deactivated.'));
    }

    public function openMerge(int $id): void
    {
        $account = Account::findOrFail($id);
        abort_unless($account->company_id === $this->company->id, 403);
        abort_if($account->is_system, 403);

        $this->reset(['mergeTargetId', 'mergeConfirmed']);
        $this->resetErrorBag(['mergeTargetId', 'mergeConfirmed']);
        $this->mergingId = $account->id;
        unset($this->mergeTargets, $this->mergeSummary);

        Flux::modal('account-merge')->show();
    }

    public function merge(): void
    {
        $this->validate([
            'mergingId' => ['required', 'integer'],
            'mergeTargetId' => ['required', 'integer'],
            'mergeConfirmed' => ['accepted'],
        ], [
            'mergeTargetId.required' => __('Choose the account to merge into.'),
            'mergeConfirmed.accepted' => __('Please confirm you understand the merge cannot be undone.'),
        ]);

        $loser = Account::findOrFail($this->mergingId);
        abort_unless($loser->company_id === $this->company->id, 403);
        $survivor = Account::findOrFail((int) $this->mergeTargetId);

        try {
            app(MergeAccounts::class)->handle($loser, $survivor);
        } catch (ValidationException $e) {
            $this->addError('mergeTargetId', collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('account-merge')->close();
        $this->reset(['mergingId', 'mergeTargetId', 'mergeConfirmed']);
        unset($this->grouped, $this->balances, $this->treeRows, $this->rollups);

        Flux::toast(variant: 'success', text: __('Accounts merged.'));
    }

    /**
     * Eligible surviving accounts for the pending merge: active, same subtype
     * and currency as the account being merged away, excluding itself and its
     * descendants (a sub-account cannot absorb its own ancestor).
     *
     * @return \Illuminate\Support\Collection<int, array{value: int, label: string}>
     */
    #[Computed]
    public function mergeTargets()
    {
        $merging = $this->mergingId ? Account::find($this->mergingId) : null;

        if (! $merging) {
            return collect();
        }

        $excluded = $this->descendantIds($merging->id);
        $excluded[] = $merging->id;

        return Account::query()
            ->where('is_active', true)
            ->where('subtype', $merging->subtype->value)
            ->where('currency_code', $merging->currency_code)
            ->whereNotIn('id', $excluded)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"]);
    }

    /**
     * A short, human summary of what moves if the pending merge proceeds.
     */
    #[Computed]
    public function mergeSummary(): string
    {
        if (! $this->mergingId) {
            return '';
        }

        $journalLines = DB::table('journal_lines')->where('account_id', $this->mergingId)->count();
        $itemDefaults = DB::table('items')->where(function ($q) {
            $q->where('income_account_id', $this->mergingId)
                ->orWhere('expense_account_id', $this->mergingId)
                ->orWhere('inventory_asset_account_id', $this->mergingId)
                ->orWhere('cogs_account_id', $this->mergingId);
        })->count();
        $recurringLines = DB::table('recurring_document_lines')->where('account_id', $this->mergingId)->count()
            + DB::table('recurring_journal_entry_lines')->where('account_id', $this->mergingId)->count();

        return __(':lines journal line(s), :items item default(s), and :recurring recurring template line(s) will move to the account you choose.', [
            'lines' => number_format($journalLines),
            'items' => number_format($itemDefaults),
            'recurring' => number_format($recurringLines),
        ]);
    }

    /**
     * All descendant account ids of the given account (children, grandchildren, …).
     *
     * @return array<int, int>
     */
    private function descendantIds(int $rootId): array
    {
        $byParent = Account::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$rootId];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($byParent->get($parentId, collect()) as $child) {
                $ids[] = (int) $child->id;
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Account>>
     */
    #[Computed]
    public function grouped()
    {
        $accounts = Account::query()
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('code', 'like', '%'.$this->search.'%');
            }))
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        return $accounts->groupBy(fn (Account $a) => $a->type->label());
    }

    /**
     * Rows to render per type group: the group's accounts flattened as a
     * parent → child tree. Roots are accounts whose parent is null or not in
     * the fetched set (e.g. filtered out as inactive); children sit directly
     * beneath their parent, sorted by code, with `depth` for indenting. While
     * a search is active the list renders flat so matches surface wherever
     * they live in the hierarchy.
     *
     * @return \Illuminate\Support\Collection<string, array<int, array{account: Account, depth: int}>>
     */
    #[Computed]
    public function treeRows()
    {
        return $this->grouped->map(function ($accounts) {
            if ($this->search !== '') {
                return $accounts
                    ->map(fn (Account $a) => ['account' => $a, 'depth' => 0])
                    ->values()
                    ->all();
            }

            // Parents are constrained to the same account type, so the fetched
            // group is the full candidate set for each account's parent.
            $ids = $accounts->pluck('id')->flip()->all();

            $byParent = $accounts->groupBy(
                fn (Account $a) => ($a->parent_id !== null && isset($ids[$a->parent_id])) ? $a->parent_id : 0
            );

            $rows = [];
            $walk = function (int $parentId, int $depth) use (&$walk, $byParent, &$rows): void {
                foreach ($byParent->get($parentId, collect())->sortBy('code') as $account) {
                    $rows[] = ['account' => $account, 'depth' => $depth];
                    $walk($account->id, $depth + 1);
                }
            };
            $walk(0, 0);

            return $rows;
        });
    }

    /**
     * Roll-up balances (own balance + all visible descendants) in cents, keyed
     * by account id — only for accounts that have at least one visible child,
     * so presence doubles as the "has visible children" flag. A pure array
     * walk over the already-computed $this->balances map; no extra
     * ReportCalculator work.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function rollups(): array
    {
        if ($this->search !== '') {
            return [];
        }

        $accounts = $this->grouped->flatten();
        $ids = $accounts->pluck('id')->flip()->all();

        /** @var array<int, array<int, int>> $children */
        $children = [];

        foreach ($accounts as $account) {
            if ($account->parent_id !== null && isset($ids[$account->parent_id])) {
                $children[$account->parent_id][] = (int) $account->id;
            }
        }

        $sum = function (int $id) use (&$sum, $children): int {
            $total = $this->balances[$id] ?? 0;

            foreach ($children[$id] ?? [] as $childId) {
                $total += $sum($childId);
            }

            return $total;
        };

        $rollups = [];

        foreach (array_keys($children) as $parentId) {
            $rollups[$parentId] = $sum($parentId);
        }

        return $rollups;
    }

    /**
     * Fiscal-aware balance for display, keyed by account id. Balance-sheet
     * accounts carry forward cumulatively; income and expense accounts show
     * only the current fiscal year's activity (start of fiscal year → today).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function balances(): array
    {
        $calculator = app(ReportCalculator::class);
        $asOf = $this->company->currentDateTime();

        return $this->grouped
            ->flatten()
            ->mapWithKeys(fn (Account $account) => [
                $account->id => $calculator->reportingBalanceAsOf($this->company, $account, $asOf),
            ])
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{value: int|string, label: string}>
     */
    #[Computed]
    public function parentOptions()
    {
        // A sub-account must roll up under a parent of the same account type, so
        // the list is scoped to the chosen type (an Expense can't nest under a Bank).
        $type = AccountSubtype::tryFrom($this->form_subtype)?->type();

        return Account::query()
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->when($type !== null, fn ($q) => $q->where('type', $type->value))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"]);
    }

    /**
     * Foreign currency codes the company has enabled (empty when multi-currency
     * is off), for the optional Bank / Credit Card denomination select.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function currencyOptions()
    {
        if (! $this->company->isMulticurrencyEnabled()) {
            return collect();
        }

        return $this->company->currencies()
            ->where('is_home', false)
            ->where('is_active', true)
            ->orderBy('currency_code')
            ->pluck('currency_code')
            ->map(fn ($c) => mb_strtoupper((string) $c));
    }

    /**
     * The default tax code select only shows for Income / Expense accounts.
     */
    #[Computed]
    public function showDefaultTaxCodeField(): bool
    {
        return in_array(
            AccountSubtype::tryFrom($this->form_subtype)?->type(),
            [AccountType::Income, AccountType::Expense],
            true,
        );
    }

    /**
     * Active tax codes eligible as this account's default, filtered by the
     * chosen type: Income accounts take sales-applicable codes, Expense
     * accounts purchase-applicable ones.
     *
     * @return \Illuminate\Support\Collection<int, TaxCode>
     */
    #[Computed]
    public function taxCodeOptions()
    {
        $type = AccountSubtype::tryFrom($this->form_subtype)?->type();

        if (! in_array($type, [AccountType::Income, AccountType::Expense], true)) {
            return collect();
        }

        return TaxCode::query()
            ->where('is_active', true)
            ->when($type === AccountType::Income, fn ($q) => $q->forSales(), fn ($q) => $q->forPurchases())
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'applies_to']);
    }

    /**
     * The currency select only shows for a Bank / Credit Card account once
     * multi-currency is enabled.
     */
    #[Computed]
    public function showCurrencyField(): bool
    {
        return $this->company->isMulticurrencyEnabled()
            && in_array(AccountSubtype::tryFrom($this->form_subtype), [AccountSubtype::Bank, AccountSubtype::CreditCard], true);
    }

    /**
     * Opening-balance capture only applies when creating a balance-sheet
     * account whose balance isn't owned by a sub-ledger. The excluded
     * control subtypes mirror TrialBalanceImporter::BLOCKED_SUBTYPES — AR,
     * AP and Inventory balances must come from their sub-ledgers.
     */
    #[Computed]
    public function openingBalanceEligible(): bool
    {
        if ($this->editingId !== null) {
            return false;
        }

        $subtype = AccountSubtype::tryFrom($this->form_subtype);

        if ($subtype === null || in_array($subtype->type(), [AccountType::Income, AccountType::Expense], true)) {
            return false;
        }

        return ! in_array($subtype, [
            AccountSubtype::AccountsReceivable,
            AccountSubtype::AccountsPayable,
            AccountSubtype::Inventory,
        ], true);
    }

    /**
     * Opening-balance inputs are home-currency only: a foreign-denominated
     * account needs an explicit journal entry carrying the exchange rate.
     */
    #[Computed]
    public function showOpeningBalanceFields(): bool
    {
        return $this->openingBalanceEligible && ($this->form_currency_code ?? '') === '';
    }

    #[Computed]
    public function showOpeningBalanceFxHint(): bool
    {
        return $this->openingBalanceEligible && ($this->form_currency_code ?? '') !== '';
    }

    /**
     * An account's type is fixed once it carries journal lines (draft or
     * posted) — retyping would rewrite every report those lines appear on.
     */
    #[Computed]
    public function subtypeLocked(): bool
    {
        return $this->editingId !== null
            && Account::find($this->editingId)?->journalLines()->exists() === true;
    }

    /**
     * An account's currency is fixed once it carries posted activity.
     */
    #[Computed]
    public function currencyLocked(): bool
    {
        return $this->editingId !== null
            && Account::find($this->editingId)?->journalLines()->exists() === true;
    }

    /**
     * Soft warning when the entered name matches another active account
     * (case-insensitive). Informational only — duplicates remain saveable.
     */
    #[Computed]
    public function duplicateNameWarning(): ?string
    {
        $name = mb_strtolower(trim($this->form_name));

        if ($name === '') {
            return null;
        }

        $exists = Account::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [$name])
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        return $exists ? __('Another active account already has this name.') : null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function subtypeOptions(): array
    {
        return collect(AccountSubtype::cases())
            ->groupBy(fn (AccountSubtype $s) => $s->type()->label())
            ->flatMap(fn ($subtypes, $type) => $subtypes->map(fn (AccountSubtype $s) => [
                'value' => $s->value,
                'label' => "{$type}: {$s->label()}",
            ]))
            ->values()
            ->all();
    }

    public function isSystemBeingEdited(): bool
    {
        return $this->editingId !== null && Account::find($this->editingId)?->is_system === true;
    }

    /**
     * The valid `subtype` column values for the import template, shown in the
     * import modal's help text so users know what to put in that column.
     *
     * @return list<string>
     */
    #[Computed]
    public function importSubtypeValues(): array
    {
        return array_map(fn (AccountSubtype $s) => $s->value, AccountSubtype::cases());
    }

    /**
     * How many previewed rows would actually be created. The importer only fills
     * summary['created'] on commit, so the preview count is derived from the rows
     * flagged 'create' (everything else is a skip of an existing code).
     */
    #[Computed]
    public function importCreatableCount(): int
    {
        return collect($this->importPreviewRows ?? [])
            ->where('action', 'create')
            ->count();
    }

    private function resetForm(): void
    {
        $this->reset([
            'form_code', 'form_name', 'form_subtype',
            'form_parent_id', 'form_description', 'form_cash_flow_activity',
            'form_gifi_code', 'form_default_tax_code_id', 'form_currency_code',
            'form_is_active', 'form_use_in_transfers', 'editingId',
            'form_opening_balance', 'form_opening_balance_as_of',
        ]);
        $this->form_is_active = true;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Chart of Accounts') }}</flux:heading>
            <flux:subheading>{{ __('Every transaction posts to these accounts.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button icon="arrow-up-tray" wire:click="openImport" data-test="import-accounts-button">
                {{ __('Import') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-account-button">
                {{ __('New account') }}
            </flux:button>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search code or name…') }}" icon="magnifying-glass" class="max-w-md" data-test="accounts-search" />
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" />

        <flux:dropdown align="end" class="ms-auto">
            <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" icon:trailing="chevron-down" data-test="accounts-columns-button">{{ __('Columns') }}</flux:button>
            <flux:menu>
                <flux:menu.checkbox.group wire:model.live="visibleColumns">
                    <flux:menu.checkbox value="subtype" keep-open data-test="accounts-column-subtype">{{ __('Subtype') }}</flux:menu.checkbox>
                    <flux:menu.checkbox value="description" keep-open data-test="accounts-column-description">{{ __('Description') }}</flux:menu.checkbox>
                    @if ($company->mapsGifiCodes())
                        <flux:menu.checkbox value="gifi" keep-open data-test="accounts-column-gifi">{{ __('GIFI') }}</flux:menu.checkbox>
                    @endif
                    @if ($company->isMulticurrencyEnabled())
                        <flux:menu.checkbox value="currency" keep-open data-test="accounts-column-currency">{{ __('Currency') }}</flux:menu.checkbox>
                    @endif
                    <flux:menu.checkbox value="balance" keep-open data-test="accounts-column-balance">{{ __('Balance') }}</flux:menu.checkbox>
                    {{-- Off by default: only useful when wiring up /api/v1, where
                         `account_id` is this numeric id (see docs/api-v1.md). --}}
                    <flux:menu.checkbox value="id" keep-open data-test="accounts-column-id">{{ __('Account ID (API)') }}</flux:menu.checkbox>
                </flux:menu.checkbox.group>
            </flux:menu>
        </flux:dropdown>
    </div>

    @php
        $showSubtypeColumn = in_array('subtype', $visibleColumns, true);
        $showDescriptionColumn = in_array('description', $visibleColumns, true);
        $showGifiColumn = in_array('gifi', $visibleColumns, true) && $company->mapsGifiCodes();
        $showCurrencyColumn = in_array('currency', $visibleColumns, true) && $company->isMulticurrencyEnabled();
        $showBalanceColumn = in_array('balance', $visibleColumns, true);
        $showIdColumn = in_array('id', $visibleColumns, true);
    @endphp

    <div class="space-y-6">
        @forelse ($this->treeRows as $typeLabel => $rows)
            <div>
                <flux:heading size="lg" class="mb-2">{{ $typeLabel }}</flux:heading>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full table-auto text-sm lg:table-fixed">
                        <colgroup>
                            <col class="lg:w-28" />
                            <col />
                            @if ($showSubtypeColumn)
                                <col class="hidden lg:table-column lg:w-56" />
                            @endif
                            @if ($showDescriptionColumn)
                                <col class="hidden lg:table-column lg:w-64" />
                            @endif
                            @if ($showGifiColumn)
                                <col class="hidden lg:table-column lg:w-24" />
                            @endif
                            @if ($showCurrencyColumn)
                                <col class="hidden lg:table-column lg:w-24" />
                            @endif
                            @if ($showBalanceColumn)
                                <col class="lg:w-40" />
                            @endif
                            @if ($showIdColumn)
                                <col class="lg:w-28" />
                            @endif
                            <col class="lg:w-16" />
                        </colgroup>
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Code') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Name') }}</th>
                                @if ($showSubtypeColumn)
                                    <th class="hidden px-4 py-2 text-left font-medium text-muted-foreground lg:table-cell dark:text-muted-foreground">{{ __('Subtype') }}</th>
                                @endif
                                @if ($showDescriptionColumn)
                                    <th class="hidden px-4 py-2 text-left font-medium text-muted-foreground lg:table-cell">{{ __('Description') }}</th>
                                @endif
                                @if ($showGifiColumn)
                                    <th class="hidden px-4 py-2 text-left font-medium text-muted-foreground lg:table-cell">{{ __('GIFI') }}</th>
                                @endif
                                @if ($showCurrencyColumn)
                                    <th class="hidden px-4 py-2 text-left font-medium text-muted-foreground lg:table-cell">{{ __('Currency') }}</th>
                                @endif
                                @if ($showBalanceColumn)
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Balance') }}</th>
                                @endif
                                @if ($showIdColumn)
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground" title="{{ __('The id to pass as account_id in the REST API') }}" data-test="accounts-id-header">{{ __('Account ID (API)') }}</th>
                                @endif
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($rows as $row)
                                @php
                                    $account = $row['account'];
                                    $depth = $row['depth'];
                                    $rollup = $this->rollups[$account->id] ?? null;
                                @endphp
                                <tr class="@if(! $account->is_active) opacity-50 @endif" data-test="account-row">
                                    <td class="px-4 py-2 font-mono" @if ($depth > 0) style="padding-left: {{ 1 + $depth * 1.25 }}rem" @endif>{{ $account->code }}</td>
                                    <td class="px-4 py-2">
                                        @if ($depth > 0)
                                            <span class="me-1 text-muted-foreground" aria-hidden="true">&rsaquo;</span>
                                        @endif
                                        {{ $account->name }}
                                        @if ($account->is_system)
                                            <flux:badge color="zinc" size="sm" class="ms-2">{{ __('System') }}</flux:badge>
                                        @endif
                                        @if ($company->mapsGifiCodes() && $account->gifi_code)
                                            <flux:badge color="sky" size="sm" class="ms-2" title="{{ __('GIFI line') }}">{{ __('GIFI') }} {{ $account->gifi_code }}</flux:badge>
                                        @endif
                                    </td>
                                    @if ($showSubtypeColumn)
                                        <td class="hidden px-4 py-2 text-muted-foreground lg:table-cell">{{ $account->subtype->label() }}</td>
                                    @endif
                                    @if ($showDescriptionColumn)
                                        <td class="hidden max-w-64 truncate px-4 py-2 text-muted-foreground lg:table-cell" title="{{ $account->description }}">{{ \Illuminate\Support\Str::limit((string) $account->description, 60) }}</td>
                                    @endif
                                    @if ($showGifiColumn)
                                        <td class="hidden px-4 py-2 font-mono text-muted-foreground lg:table-cell">{{ $account->gifi_code }}</td>
                                    @endif
                                    @if ($showCurrencyColumn)
                                        <td class="hidden px-4 py-2 font-mono text-muted-foreground lg:table-cell">{{ $account->currency_code ?? $company->currency_code }}</td>
                                    @endif
                                    @if ($showBalanceColumn)
                                        <td class="px-4 py-2 text-right font-mono">
                                            {{ number_format(($this->balances[$account->id] ?? 0) / 100, 2) }}
                                            @if ($rollup !== null)
                                                <div class="font-sans text-xs text-muted-foreground" data-test="account-rollup-balance">
                                                    {{ number_format($rollup / 100, 2) }} {{ __('incl. sub-accounts') }}
                                                </div>
                                            @endif
                                        </td>
                                    @endif
                                    @if ($showIdColumn)
                                        <td class="px-4 py-2 text-right font-mono text-muted-foreground" data-test="account-id-cell">{{ $account->id }}</td>
                                    @endif
                                    <td class="px-4 py-2 text-right">
                                        <flux:dropdown align="end">
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil" wire:click="openEdit({{ $account->id }})" data-test="account-edit">{{ __('Edit') }}</flux:menu.item>
                                                @unless ($account->is_system)
                                                    <flux:menu.item icon="{{ $account->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleActive({{ $account->id }})">
                                                        {{ $account->is_active ? __('Deactivate') : __('Activate') }}
                                                    </flux:menu.item>
                                                    <flux:menu.item icon="arrows-pointing-in" wire:click="openMerge({{ $account->id }})" data-test="merge-account-button">
                                                        {{ __('Merge…') }}
                                                    </flux:menu.item>
                                                @endunless
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <flux:text class="py-8 text-center text-muted-foreground">{{ __('No accounts match your search.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="account-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit account') : __('New account') }}</flux:heading>
                @if ($this->isSystemBeingEdited())
                    <flux:subheading>{{ __('This is a system account. Its type cannot be changed.') }}</flux:subheading>
                @elseif ($this->subtypeLocked)
                    <flux:subheading data-test="account-subtype-locked-note">{{ __('This account has transactions, so its type can no longer be changed.') }}</flux:subheading>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input
                    wire:model="form_code"
                    :label="__('Code')"
                    required
                    data-test="account-code-input"
                />
                <div class="md:col-span-2">
                    <flux:input wire:model.live.debounce.500ms="form_name" :label="__('Name')" required data-test="account-name-input" />
                    @if ($this->duplicateNameWarning)
                        <flux:text class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" data-test="duplicate-name-warning">
                            <flux:icon name="exclamation-triangle" class="size-4 shrink-0" />
                            {{ $this->duplicateNameWarning }}
                        </flux:text>
                    @endif
                </div>
            </div>

            <flux:select wire:model.live="form_subtype" :label="__('Type')" required :disabled="$this->isSystemBeingEdited() || $this->subtypeLocked" data-test="account-subtype-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->subtypeOptions as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form_parent_id" :label="__('Parent account (optional)')" data-test="account-parent-select">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->parentOptions as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="form_description" :label="__('Description')" rows="2" />

            <flux:select wire:model="form_cash_flow_activity" :label="__('Cash flow activity')" :description="__('Override which section this account appears under on the Statement of Cash Flows. Only applies to balance-sheet accounts.')" data-test="account-cash-flow-activity-select">
                <flux:select.option value="">{{ __('Auto (classify by type)') }}</flux:select.option>
                @foreach (CashFlowActivity::cases() as $activity)
                    <flux:select.option :value="$activity->value">{{ __($activity->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($company->mapsGifiCodes())
                <flux:select
                    wire:model="form_gifi_code"
                    :label="__('GIFI line')"
                    :description="__('The CRA GIFI code this account maps to on the GIFI Statement.')"
                    data-test="account-gifi-select"
                >
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach (\App\Support\Gifi\GifiCatalog::options() as $sectionLabel => $options)
                        @foreach ($options as $option)
                            <flux:select.option :value="$option['value']">{{ $sectionLabel }}: {{ $option['label'] }}</flux:select.option>
                        @endforeach
                    @endforeach
                </flux:select>
            @endif

            @if ($this->showDefaultTaxCodeField)
                <flux:select
                    wire:model="form_default_tax_code_id"
                    :label="__('Default tax code')"
                    :description="__('Pre-selected tax code for transaction lines posting to this account.')"
                    data-test="account-default-tax-code-select"
                >
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->taxCodeOptions as $taxCode)
                        <flux:select.option :value="$taxCode->id">{{ $taxCode->code }} — {{ $taxCode->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($this->showCurrencyField)
                <flux:select
                    wire:model="form_currency_code"
                    :label="__('Currency')"
                    :description="__('Foreign-denominated Bank / Credit Card account. Fixed once it has activity.')"
                    :disabled="$this->currencyLocked"
                    data-test="account-currency-select"
                >
                    <flux:select.option value="">{{ $company->currency_code }} ({{ __('home') }})</flux:select.option>
                    @foreach ($this->currencyOptions as $code)
                        <flux:select.option :value="$code">{{ $code }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($this->showOpeningBalanceFields)
                @if (\App\Models\OpeningBalanceState::for($company) !== null && auth()->user()?->ownsCompany($company))
                    <flux:callout icon="scale" data-test="account-opening-balance-workspace-hint">
                        <flux:callout.text>
                            {{ __('This company has an Opening Balances workspace — balances entered there stay reconciled against your draft trial balance.') }}
                            <flux:link :href="route('opening-balances.index', ['company' => $company->slug])" wire:navigate>{{ __('Open it') }}</flux:link>
                        </flux:callout.text>
                    </flux:callout>
                @endif
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input
                        wire:model="form_opening_balance"
                        :label="__('Opening balance (optional)')"
                        :description="__('Posts a journal entry against Opening Balance Equity.')"
                        placeholder="0.00"
                        data-test="account-opening-balance"
                    />
                    <flux:input
                        type="date"
                        wire:model="form_opening_balance_as_of"
                        :label="__('As of')"
                        data-test="account-opening-balance-as-of"
                    />
                </div>
            @elseif ($this->showOpeningBalanceFxHint)
                <flux:text class="text-sm text-muted-foreground" data-test="account-opening-balance-fx-hint">
                    {{ __('Foreign-currency accounts can’t take an opening balance here — record it with a journal entry at the correct exchange rate.') }}
                </flux:text>
            @endif

            @if ($editingId)
                <div>
                    <flux:label>{{ __('Account ID (API)') }}</flux:label>
                    <div class="mt-1 font-mono text-sm text-muted-foreground">{{ $editingId }}</div>
                </div>
            @endif

            @if ($form_subtype && $form_subtype !== \App\Enums\AccountSubtype::Bank->value)
                <flux:switch wire:model="form_use_in_transfers" :label="__('Include in transfers')" :description="__('Makes this account available in the From / To dropdowns when recording a transfer (e.g. a line of credit).')" />
            @endif

            <flux:switch wire:model="form_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="account-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="account-merge" class="max-w-lg">
        <form wire:submit="merge" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Merge account') }}</flux:heading>
                @if ($mergingId && ($mergingAccount = Account::find($mergingId)))
                    <flux:subheading>{{ $mergingAccount->code }} — {{ $mergingAccount->name }}</flux:subheading>
                @endif
            </div>

            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    {{ __('Merging cannot be undone. All transaction history, defaults, and sub-accounts move to the account you choose, and this account is deactivated and removed from the list.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:select wire:model="mergeTargetId" :label="__('Merge into')" data-test="account-merge-target">
                <flux:select.option value="">{{ __('— Select account —') }}</flux:select.option>
                @foreach ($this->mergeTargets as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($mergingId)
                <flux:text class="text-sm text-muted-foreground">{{ $this->mergeSummary }}</flux:text>
            @endif

            <flux:checkbox wire:model="mergeConfirmed" :label="__('I understand this cannot be undone.')" data-test="account-merge-confirm" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="account-merge-submit">{{ __('Merge') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="account-import" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Import accounts') }}</flux:heading>
                <flux:subheading>{{ __('Bulk-add accounts from a CSV. Codes that already exist are left untouched.') }}</flux:subheading>
            </div>

            <flux:callout icon="information-circle">
                <flux:callout.text>
                    <p>{{ __('Download the template, fill in one account per row, then upload it here.') }}</p>
                    <p class="mt-2">{{ __('The "subtype" column accepts one of:') }}</p>
                    <p class="mt-1 font-mono text-xs break-words">{{ implode(', ', $this->importSubtypeValues) }}</p>
                    <p class="mt-2">{{ __('To nest sub-accounts, put the parent\'s code in "parent_code" and list the parent before its children.') }}</p>
                </flux:callout.text>
            </flux:callout>

            <flux:button
                icon="arrow-down-tray"
                size="sm"
                variant="filled"
                :href="route('accounts.template', ['company' => $company->slug])"
                target="_blank"
                data-test="download-accounts-template"
            >
                {{ __('Download template') }}
            </flux:button>

            <div>
                <label class="mb-2 block text-sm font-medium">{{ __('CSV file') }}</label>
                <input type="file" wire:model="importFile" accept=".csv,.txt" class="block w-full text-sm" data-test="accounts-import-file" />
                <div wire:loading wire:target="importFile" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>
                @error('importFile')
                    <p class="mt-2 text-sm text-rose-600" data-test="accounts-import-file-error">{{ $message }}</p>
                @enderror
            </div>

            @if ($importErrors)
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-900 dark:bg-rose-950/40" data-test="accounts-import-errors">
                    <p class="mb-1 text-sm font-medium text-rose-700 dark:text-rose-300">{{ __('Problems found') }}</p>
                    <ul class="list-inside list-disc text-sm text-rose-700 dark:text-rose-300">
                        @foreach ($importErrors as $error)
                            <li>{{ $error['row'] > 0 ? __('Row :n: ', ['n' => $error['row']]) : '' }}{{ $error['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($importPreviewRows !== null)
                <div data-test="accounts-import-preview">
                    <p class="mb-2 text-sm text-muted-foreground">
                        {{ __(':create to create · :skip skipped (already exist) · :rows row(s) total', [
                            'create' => $this->importCreatableCount,
                            'skip' => $importSummary['skipped_existing'] ?? 0,
                            'rows' => $importSummary['rows'] ?? 0,
                        ]) }}
                    </p>
                    @if ($importPreviewRows)
                        <div class="max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 bg-zinc-50 text-left dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-3 py-2 font-medium">{{ __('Row') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Code') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($importPreviewRows as $row)
                                        <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                            <td class="px-3 py-1.5 text-muted-foreground">{{ $row['row'] }}</td>
                                            <td class="px-3 py-1.5 font-mono">{{ $row['code'] }}</td>
                                            <td class="px-3 py-1.5">{{ $row['name'] }}</td>
                                            <td class="px-3 py-1.5">{{ $row['action'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                @if ($importPreviewRows === null)
                    <flux:button variant="primary" wire:click="previewImport" :disabled="! $importFile" data-test="accounts-import-preview-button">
                        {{ __('Preview') }}
                    </flux:button>
                @else
                    <flux:button
                        variant="primary"
                        wire:click="runImport"
                        :disabled="$this->importCreatableCount === 0 || ! empty($importErrors)"
                        data-test="accounts-import-submit"
                    >
                        {{ __('Import') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</section>
