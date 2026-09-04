<?php

use App\Actions\Banking\ConfirmStatementLineSuggestions;
use App\Actions\Banking\CreateBankRuleFromLine;
use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Jobs\ProcessBankStatementImport;
use App\Livewire\Concerns\ManagesStatementLineContacts;
use App\Livewire\Concerns\ManagesStatementLineTax;
use App\Models\Account;
use App\Models\BankImportProfile;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Services\AttachmentService;
use App\Services\Banking\Import\BankRuleEngine;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Services\Classification\CategorySuggester;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import statement')] class extends Component {
    use ManagesStatementLineContacts, ManagesStatementLineTax, WithFileUploads;

    public Company $company;

    public ?int $account_id = null;

    public ?int $importId = null;

    public mixed $upload = null;

    /** @var array<string, mixed> Editable column mapping shown in the wizard. */
    public array $mapping = [];

    public bool $saveProfile = false;

    public string $profileName = '';

    /** @var array<int, int|string> Per-line "Add" category selections, keyed by line id. */
    public array $lineCategory = [];

    /** @var array<int, int|string> Per-line "Pay bill" choice, keyed by line id ('' = record as expense). */
    public array $lineBill = [];

    /** @var array<int, bool> Suggested rows the user opened with "Change". */
    public array $editingLines = [];

    /** Set by the commit guard for the modal. */
    public int $unconfirmedCount = 0;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $first = Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        $this->account_id = request('account') ? (int) request('account') : $first?->id;

        $this->resumeLatestImport();
    }

    public function updatedAccountId(): void
    {
        $this->reset('importId', 'upload', 'mapping', 'lineCategory', 'lineContact', 'lineBill', 'lineTax', 'editingLines', 'lineHint', 'saveProfile', 'profileName');
        $this->resumeLatestImport();
    }

    /** Pick up an in-flight (not yet committed) import for this account, if any. */
    protected function resumeLatestImport(): void
    {
        if (! $this->account_id) {
            return;
        }

        $import = BankStatementImport::query()
            ->where('account_id', $this->account_id)
            ->whereNotIn('status', [BankStatementImportStatus::Committed->value])
            ->latest('id')
            ->first();

        $this->importId = $import?->id;

        if ($import) {
            $this->afterStatusResolved($import);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function import(): ?BankStatementImport
    {
        return $this->importId ? BankStatementImport::with(['lines.suggestedAccount', 'lines.suggestedBill'])->find($this->importId) : null;
    }

    /**
     * Accounts a user can post an "Add" line to (any postable category, minus the
     * AR/AP control accounts and Undeposited Funds).
     *
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    public function startImport(): void
    {
        if (! $this->account_id) {
            return;
        }

        $extensions = config('banking.statement_import.allowed_extensions');
        $maxKb = (int) config('banking.statement_import.max_kilobytes');

        $this->validate(
            ['upload' => ['required', 'file', \Illuminate\Validation\Rules\File::types($extensions)->max($maxKb)]],
            [],
            ['upload' => __('statement file')],
        );

        $format = BankStatementFormat::fromExtension($this->upload->getClientOriginalExtension() ?: 'csv');

        if ($format === null) {
            Flux::toast(variant: 'danger', text: __('Unsupported file type.'));

            return;
        }

        $account = Account::query()->findOrFail($this->account_id);

        $import = BankStatementImport::create([
            'account_id' => $account->id,
            'source_format' => $format->value,
            'original_filename' => $this->upload->getClientOriginalName(),
            'status' => BankStatementImportStatus::Uploaded->value,
            'created_by_user_id' => Auth::id(),
        ]);

        app(AttachmentService::class)->upload($import, [$this->upload], Auth::id());

        // Link the just-created attachment back onto the import so the parser job
        // can find the file and the committer can re-point it at the reconciliation.
        $import->update(['attachment_id' => \App\Models\Attachment::query()
            ->where('attachable_type', $import->getMorphClass())
            ->where('attachable_id', $import->id)
            ->latest('id')
            ->value('id')]);

        $this->importId = $import->id;
        $this->upload = null;

        // Run inline: statements are small and this avoids requiring a queue worker
        // for a manual upload. The job still binds/restores the tenant and records
        // failures, so a secured/scanned PDF surfaces a clear message rather than hanging.
        ProcessBankStatementImport::dispatchSync($import->id);

        unset($this->import);
        $this->afterStatusResolved($import->fresh());
    }

    /** Polled while the job parses + matches. */
    public function tick(): void
    {
        unset($this->import);

        if ($this->import) {
            $this->afterStatusResolved($this->import);
        }
    }

    protected function afterStatusResolved(BankStatementImport $import): void
    {
        if ($import->status === BankStatementImportStatus::NeedsMapping && $this->mapping === []) {
            $this->mapping = $this->defaultMappingFromProbe($import);
        }

        if ($import->status === BankStatementImportStatus::Ready) {
            $this->seedLineState($import);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultMappingFromProbe(BankStatementImport $import): array
    {
        $headers = $import->parse_meta['headers'] ?? [];

        return [
            'amountMode' => 'single',
            'dateColumn' => $headers[0] ?? '',
            'descriptionColumn' => $headers[1] ?? '',
            'amountColumn' => '',
            'debitColumn' => '',
            'creditColumn' => '',
            'balanceColumn' => '',
            'dateFormat' => 'Y-m-d',
            'decimalSeparator' => '.',
            'flipSign' => false,
        ];
    }

    public function applyMapping(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        $mapping = [
            'amountMode' => $this->mapping['amountMode'] ?? 'single',
            'dateColumn' => $this->mapping['dateColumn'] ?: null,
            'descriptionColumns' => array_values(array_filter([$this->mapping['descriptionColumn'] ?? null])),
            'amountColumn' => $this->mapping['amountColumn'] ?: null,
            'debitColumn' => $this->mapping['debitColumn'] ?: null,
            'creditColumn' => $this->mapping['creditColumn'] ?: null,
            'balanceColumn' => $this->mapping['balanceColumn'] ?: null,
            'dateFormat' => $this->mapping['dateFormat'] ?: 'Y-m-d',
            'decimalSeparator' => $this->mapping['decimalSeparator'] ?: '.',
            'flipSign' => (bool) ($this->mapping['flipSign'] ?? false),
        ];

        if ($this->saveProfile && trim($this->profileName) !== '') {
            BankImportProfile::create([
                'account_id' => $import->account_id,
                'name' => trim($this->profileName),
                'source_format' => $import->source_format->value,
                'mapping' => $mapping,
                'header_signature' => $import->parse_meta['header_signature'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);
        }

        ProcessBankStatementImport::dispatchSync($import->id, $mapping);

        unset($this->import);
        $this->afterStatusResolved($this->import);
    }

    protected function seedLineState(BankStatementImport $import): void
    {
        $this->lineCategory = [];
        $this->lineContact = [];
        $this->lineBill = [];
        $this->lineTax = [];

        foreach ($import->lines as $line) {
            $this->lineCategory[$line->id] = $line->suggested_account_id ?? '';
            $this->seedLineContact($line);
            $this->lineBill[$line->id] = $line->suggested_bill_id ?? '';
            $this->seedLineTax($line);
        }
    }

    /**
     * After a rule was created (which may have filled sibling lines), pull any
     * newly suggested account / payee / bill into the untouched row state.
     */
    protected function refreshSeedsFromImport(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        foreach ($import->lines as $line) {
            if (($this->lineCategory[$line->id] ?? '') === '' && $line->suggested_account_id !== null) {
                $this->lineCategory[$line->id] = $line->suggested_account_id;
            }

            if ($this->lineContactState($line->id)['id'] === null && $line->suggested_contact_id !== null) {
                $this->seedLineContact($line);
            }

            if (($this->lineBill[$line->id] ?? '') === '' && $line->suggested_bill_id !== null) {
                $this->lineBill[$line->id] = $line->suggested_bill_id;
            }

            if (($this->lineTax[$line->id] ?? []) === [] && array_filter($line->suggestedTaxCodeIds()) !== []) {
                $this->seedLineTax($line);
            }
        }
    }

    /**
     * The row's derived state for rendering: the enum's own statuses, plus
     * "suggested" for an Unmatched line the pipeline pre-filled but nobody has
     * confirmed, and "confirmed" for a Created line.
     */
    public function rowState(BankStatementLine $line): string
    {
        return match (true) {
            $line->match_status === StatementLineMatchStatus::Duplicate => 'duplicate',
            $line->match_status === StatementLineMatchStatus::Ignored => 'ignored',
            $line->match_status === StatementLineMatchStatus::Suggested => 'possible-match',
            $line->match_status === StatementLineMatchStatus::Matched => 'matched',
            $line->match_status === StatementLineMatchStatus::Created => 'confirmed',
            $line->hasUnconfirmedSuggestion() => 'suggested',
            default => 'unmatched',
        };
    }

    /**
     * Open-bill offers for every vendor-tagged outflow still open for decision,
     * keyed by line id — one query for the whole import.
     *
     * @return array<int, Collection<int, \App\Models\Bill>>
     */
    #[Computed]
    public function billCandidates(): array
    {
        $import = $this->import;

        if (! $import) {
            return [];
        }

        $lines = $import->lines->filter(fn (BankStatementLine $l): bool => $l->isOutflow()
            && $l->created_journal_entry_id === null
            && ! in_array($l->match_status, [StatementLineMatchStatus::Ignored, StatementLineMatchStatus::Duplicate, StatementLineMatchStatus::Matched], true));

        $overrides = [];
        foreach ($lines as $line) {
            $overrides[$line->id] = $this->lineContactId($line);
        }

        return app(OpenBillMatcher::class)->forLines($lines, $overrides);
    }

    /**
     * Lines an active bank rule already covers, keyed by line id — disables
     * "Always do this" for them.
     *
     * @return array<int, \App\Models\BankRule>
     */
    #[Computed]
    public function ruleCoveredLines(): array
    {
        $import = $this->import;

        if (! $import) {
            return [];
        }

        return app(BankRuleEngine::class)->firstMatchingForMany(
            (int) $import->company_id,
            $import->lines->mapWithKeys(fn (BankStatementLine $l) => [$l->id => $l->description])->all(),
        );
    }

    public function updatedLineCategory(mixed $value, int|string $key): void
    {
        $line = $this->lineFor((int) $key);

        if (! $line) {
            return;
        }

        if ($value === '' || $value === null) {
            $line->forceFill([
                'match_status' => StatementLineMatchStatus::Unmatched->value,
                'suggested_account_id' => null,
            ])->save();
            unset($this->lineHint[$line->id]);
        } else {
            // A pick is a decision: the line is confirmed as an "Add".
            $line->forceFill([
                'match_status' => StatementLineMatchStatus::Created->value,
                'suggested_account_id' => (int) $value,
                'suggestion_source' => StatementSuggestionSource::User->value,
            ])->save();
            $this->hintForVendorDefault($line, (int) $value);
        }

        unset($this->import, $this->ruleCoveredLines);
    }

    public function updatedLineBill(mixed $value, int|string $key): void
    {
        $line = $this->lineFor((int) $key);

        if (! $line) {
            return;
        }

        $line->forceFill(match (true) {
            $value === '' || $value === null => ['suggested_bill_id' => null, 'suggested_bill_allocations' => null],
            $value === 'allocations' => ['suggested_bill_id' => null],
            default => ['suggested_bill_id' => (int) $value, 'suggested_bill_allocations' => null],
        })->save();

        unset($this->import);
    }

    // ---- "Pay bills…" picker: apply one outflow across several open bills ----

    public ?int $payBillsLineId = null;

    public int $payBillsTargetCents = 0;

    /** @var array<int, array{bill_id: int, bill_no: string, type: string, due_date: string, balance: int, apply: string}> */
    public array $payBillsRows = [];

    /**
     * Open bills (both kinds) per payee shown on the page, one grouped query —
     * gates the "Pay bills…" button.
     *
     * @return array<int, int> contact id => open bill count
     */
    #[Computed]
    public function openBillCounts(): array
    {
        $contactIds = ($this->import?->lines ?? collect())
            ->map(fn (BankStatementLine $l) => $l->isOutflow() ? $this->lineContactId($l) : null)
            ->filter()
            ->unique()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        return Bill::query()
            ->openWithBalance()
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS n')
            ->groupBy('contact_id')
            ->pluck('n', 'contact_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    public function openPayBills(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if ($line === null) {
            return;
        }

        $contactId = $this->lineContactId($line);
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;

        if ($contact === null) {
            Flux::toast(variant: 'danger', text: __('Choose the payee first.'));

            return;
        }

        $bills = app(OpenBillMatcher::class)->openBillsFor($line, $contact);

        if ($bills->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('No open bills for this payee.'));

            return;
        }

        $target = abs((int) $line->amount_cents);

        // Pre-fill: the saved allocation, else the offered single bill, else the
        // oldest bills first up to the transaction total (last one partial).
        $existing = collect($line->suggestedBillAllocations())->pluck('amount_cents', 'bill_id');
        if ($existing->isEmpty() && $line->suggested_bill_id !== null) {
            $existing = collect([(int) $line->suggested_bill_id => $target]);
        }
        $remaining = $target;

        $this->payBillsRows = [];
        foreach ($bills as $bill) {
            $balance = $bill->balanceCents();

            if ($existing->isNotEmpty()) {
                $apply = (int) ($existing[$bill->id] ?? 0);
            } else {
                $apply = min($balance, $remaining);
                $remaining -= $apply;
            }

            $type = $bill->getAttribute('bill_type');

            $this->payBillsRows[] = [
                'bill_id' => (int) $bill->id,
                'bill_no' => (string) ($bill->bill_no ?: '#'.$bill->id),
                'type' => $type instanceof BillType ? $type->value : BillType::Vendor->value,
                'due_date' => $bill->due_date ? \Carbon\CarbonImmutable::parse($bill->due_date)->toDateString() : '',
                'balance' => $balance,
                'apply' => $apply > 0 ? number_format($apply / 100, 2, '.', '') : '',
            ];
        }

        $this->payBillsLineId = $lineId;
        $this->payBillsTargetCents = $target;
        $this->resetErrorBag('payBillsRows');

        Flux::modal('pay-bills')->show();
    }

    public function payBillsTotal(): int
    {
        return (int) collect($this->payBillsRows)->sum(fn (array $row): int => Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0);
    }

    public function savePayBills(): void
    {
        $line = $this->payBillsLineId ? $this->lineFor($this->payBillsLineId) : null;

        if ($line === null) {
            $this->cancelPayBills();

            return;
        }

        $allocations = [];

        foreach ($this->payBillsRows as $row) {
            $cents = Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0;

            if ($cents <= 0) {
                continue;
            }

            if ($cents > (int) $row['balance']) {
                $this->addError('payBillsRows', __('Bill :no cannot be paid more than its open balance.', ['no' => $row['bill_no']]));

                return;
            }

            $allocations[] = ['bill_id' => (int) $row['bill_id'], 'amount_cents' => $cents];
        }

        if (array_sum(array_column($allocations, 'amount_cents')) !== $this->payBillsTargetCents) {
            $this->addError('payBillsRows', __('The amounts applied must add up to the transaction total.'));

            return;
        }

        // Always stored as a set: a partial payment of even one bill is a set.
        $line->forceFill(['suggested_bill_id' => null, 'suggested_bill_allocations' => $allocations])->save();
        $this->lineBill[$line->id] = 'allocations';

        $this->cancelPayBills();
        unset($this->import, $this->billCandidates);

        Flux::toast(variant: 'success', text: __('Bills chosen — confirm the line to record the payment.'));
    }

    public function cancelPayBills(): void
    {
        $this->payBillsLineId = null;
        $this->payBillsTargetCents = 0;
        $this->payBillsRows = [];
        Flux::modal('pay-bills')->close();
    }

    /**
     * "Pay :n bills (:amount)" for a stored allocation, or null.
     */
    public function allocationLabel(BankStatementLine $line): ?string
    {
        $allocations = $line->suggestedBillAllocations();

        if ($allocations === []) {
            return null;
        }

        return __('Pay :n bills (:amount)', [
            'n' => count($allocations),
            'amount' => number_format(array_sum(array_column($allocations, 'amount_cents')) / 100, 2),
        ]);
    }

    public function confirmSuggestion(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if ($line) {
            app(ConfirmStatementLineSuggestions::class)->handleLine($line);
        }

        unset($this->editingLines[$lineId], $this->import);
    }

    public function confirmAllSuggestions(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        $count = app(ConfirmStatementLineSuggestions::class)->handle($import);
        $this->editingLines = [];
        unset($this->import);

        Flux::toast(variant: 'success', text: trans_choice('{1} :count suggestion confirmed.|[2,*] :count suggestions confirmed.', $count, ['count' => $count]));
    }

    public function editSuggestion(int $lineId): void
    {
        $this->editingLines[$lineId] = true;
    }

    /**
     * "Always do this": write a bank rule for this payee with the row's
     * category (and vendor), then re-scan the import so siblings fill in.
     */
    public function createRule(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if (! $line) {
            return;
        }

        $accountId = (int) ($this->lineCategory[$lineId] ?? 0) ?: (int) $line->suggested_account_id;

        if ($accountId === 0) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        try {
            $rule = app(CreateBankRuleFromLine::class)->handle($line, $accountId, $this->lineContactId($line));
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        unset($this->import, $this->ruleCoveredLines, $this->billCandidates, $this->contactNames);
        $this->refreshSeedsFromImport();

        Flux::toast(variant: 'success', text: __('Rule ":name" created — edit it under Bank rules.', ['name' => $rule->name]));
    }

    protected function statementLineForContact(int $lineId): ?BankStatementLine
    {
        return $this->lineFor($lineId);
    }

    protected function statementLineForTax(int $lineId): ?BankStatementLine
    {
        return $this->lineFor($lineId);
    }

    protected function afterLineTaxChanged(BankStatementLine $line): void
    {
        unset($this->import);
    }

    /**
     * @return Collection<int, int>
     */
    protected function suggestedContactIdsOnPage(): Collection
    {
        return $this->import?->lines->pluck('suggested_contact_id')->filter()->map(fn ($id): int => (int) $id)->values() ?? collect();
    }

    /**
     * Picking a payee pre-fills an empty category from their default (or most
     * used) account and looks for an open bill of this amount; both stay
     * suggestions the row still has to confirm.
     */
    protected function afterLineContactChanged(BankStatementLine $line, ?Contact $contact): void
    {
        $lineId = $line->id;

        if ($contact === null) {
            $this->lineBill[$lineId] = '';
            unset($this->lineHint[$lineId], $this->import, $this->billCandidates);

            return;
        }

        $suggester = app(CategorySuggester::class);
        $suggestion = null;
        $accountPrefilled = false;

        if ((int) ($this->lineCategory[$lineId] ?? 0) === 0) {
            $suggestion = $suggester->fromContact((int) $line->company_id, (int) $contact->id);

            if ($suggestion !== null) {
                $this->lineCategory[$lineId] = $suggestion->accountId;
                $line->forceFill([
                    'suggested_account_id' => $suggestion->accountId,
                    'suggestion_source' => $line->suggestion_source?->value ?? StatementSuggestionSource::ContactDefault->value,
                    'match_reason' => $suggestion->reason,
                ])->save();
                $this->lineHint[$lineId] = __("Pre-filled from :name's default account — confirm to add.", ['name' => $contact->display_name]);
                $accountPrefilled = true;
                // Keep the row's controls open so the pre-fill doesn't collapse the row mid-edit.
                $this->editingLines[$lineId] = true;
            }
        } else {
            $this->hintForVendorDefault($line->fresh() ?? $line, (int) $this->lineCategory[$lineId], $contact);
        }

        // The vendor's default tax code fills an empty tax picker on money out.
        if ($line->isOutflow() && array_filter($this->lineTaxIds($line)) === []) {
            $taxCodeId = $suggestion?->taxCodeId ?? $suggester->defaultTaxCodeFor((int) $line->company_id, (int) $contact->id);

            if ($taxCodeId !== null) {
                $this->lineTax[$lineId] = [$taxCodeId];
                $line->forceFill(['suggested_tax_code_id' => $taxCodeId, 'suggested_secondary_tax_code_id' => $suggestion?->secondaryTaxCodeId])->save();
                $this->lineHint[$lineId] = match (true) {
                    $accountPrefilled => __("Pre-filled from :name's default account and tax — confirm to add.", ['name' => $contact->display_name]),
                    isset($this->lineHint[$lineId]) => $this->lineHint[$lineId].' '.__('Tax pre-filled from their default.'),
                    default => __("Tax pre-filled from :name's default.", ['name' => $contact->display_name]),
                };
            }
        }

        $matcher = app(OpenBillMatcher::class);
        $fresh = $line->fresh() ?? $line;
        $offer = $matcher->forLine($fresh, (int) $contact->id);
        $allocation = $offer === null ? $matcher->allocationFor($fresh, (int) $contact->id) : null;

        $this->lineBill[$lineId] = $offer?->id ?? ($allocation !== null ? 'allocations' : '');
        $line->forceFill(['suggested_bill_id' => $offer?->id, 'suggested_bill_allocations' => $allocation])->save();

        unset($this->import, $this->billCandidates, $this->openBillCounts);
    }

    /**
     * When the row has a vendor with no default expense account, say that the
     * chosen category will become it (RecordStatementLine does the write).
     */
    protected function hintForVendorDefault(BankStatementLine $line, int $accountId, ?Contact $contact = null): void
    {
        $contactId = $contact?->id ?? $this->lineContactId($line);

        if (! $line->isOutflow() || $contactId === null) {
            return;
        }

        $contact ??= Contact::query()->find($contactId);

        if ($contact === null || $contact->default_expense_account_id !== null) {
            unset($this->lineHint[$line->id]);

            return;
        }

        $this->lineHint[$line->id] = __("Will use as :name's default account.", ['name' => $contact->display_name]);
    }

    public function confirm(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if ($line && $line->matched_journal_line_id !== null) {
            $line->forceFill(['match_status' => StatementLineMatchStatus::Matched->value])->save();
            unset($this->import);
        }
    }

    public function ignore(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if ($line) {
            $line->forceFill([
                'match_status' => StatementLineMatchStatus::Ignored->value,
                'suggested_account_id' => null,
                'suggested_contact_id' => null,
                'suggested_bill_id' => null,
                'suggested_bill_allocations' => null,
                'suggested_tax_code_id' => null,
                'suggested_secondary_tax_code_id' => null,
                'suggestion_source' => null,
            ])->save();
            $this->lineCategory[$lineId] = '';
            $this->lineBill[$lineId] = '';
            $this->lineTax[$lineId] = [];
            $this->seedLineContact($line);
            unset($this->lineHint[$lineId], $this->editingLines[$lineId], $this->import, $this->billCandidates);
        }
    }

    public function restore(int $lineId): void
    {
        $line = $this->lineFor($lineId);

        if ($line) {
            $status = $line->matched_journal_line_id !== null
                ? StatementLineMatchStatus::Matched
                : StatementLineMatchStatus::Unmatched;
            $line->forceFill(['match_status' => $status->value])->save();
            unset($this->import);
        }
    }

    /**
     * Suggestions are pre-fill only: if any remain unconfirmed, ask before
     * importing rather than silently leaving them behind.
     */
    public function commitImport(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        $unconfirmed = app(StatementImportCommitter::class)->unconfirmedSuggestionCount($import);

        if ($unconfirmed > 0) {
            $this->unconfirmedCount = $unconfirmed;
            Flux::modal('unconfirmed-suggestions')->show();

            return;
        }

        $this->performCommit(false);
    }

    public function confirmAllAndCommit(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        app(ConfirmStatementLineSuggestions::class)->handle($import);
        unset($this->import);
        Flux::modal('unconfirmed-suggestions')->close();

        $this->performCommit(false);
    }

    public function commitWithoutSuggestions(): void
    {
        Flux::modal('unconfirmed-suggestions')->close();

        $this->performCommit(true);
    }

    private function performCommit(bool $leaveUnconfirmed): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        $leftBehind = $leaveUnconfirmed ? app(StatementImportCommitter::class)->unconfirmedSuggestionCount($import) : 0;

        try {
            app(StatementImportCommitter::class)->commit($import, Auth::id(), $leaveUnconfirmed);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $leftBehind > 0
            ? trans_choice('{1} Statement imported. :count line is waiting in For Review.|[2,*] Statement imported. :count lines are waiting in For Review.', $leftBehind, ['count' => $leftBehind])
            : __('Statement imported — review and finish the reconciliation.'));

        $this->redirectRoute('banking.reconcile', ['company' => $this->company->slug, 'account' => $import->account_id], navigate: true);
    }

    public function discard(): void
    {
        $import = $this->import;

        if ($import && ! $import->isCommitted()) {
            $import->delete();
        }

        $this->reset('importId', 'upload', 'mapping', 'lineCategory', 'lineContact', 'lineBill', 'lineTax', 'editingLines', 'lineHint', 'saveProfile', 'profileName');
        unset($this->import);
    }

    private function lineFor(int $lineId): ?BankStatementLine
    {
        return BankStatementLine::query()
            ->where('bank_statement_import_id', $this->importId)
            ->find($lineId);
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents, $this->company->currency_code ?? 'CAD')->format();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Import statement') }}</flux:heading>
            <flux:subheading>
                {{ __('Upload a file your bank gives you (CSV, Excel, OFX/QFX/QBO, or PDF) and we will match it against your books.') }}
            </flux:subheading>
            <flux:link :href="route('banking.review', ['company' => $company->slug])" wire:navigate class="mt-1 inline-block text-sm" data-test="for-review-link">{{ __('Go to For Review →') }}</flux:link>
        </div>

        <flux:select wire:model.live="account_id" :label="__('Account')" class="min-w-[280px]">
            @foreach ($this->bankAccounts as $opt)
                <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @php $import = $this->import; @endphp

    @if (! $import || $import->status === \App\Enums\BankStatementImportStatus::Uploaded)
        {{-- STEP 1 — upload --}}
        <div class="max-w-2xl space-y-4 rounded-lg border border-border bg-card p-6">
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('Tip: OFX / QFX is the most reliable') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Most banks offer a "Download to Quicken/QuickBooks" option. That file is structured and needs no column mapping. A CSV export works too — we will detect or let you map its columns.') }}
                </flux:callout.text>
            </flux:callout>

            <div>
                <label class="block text-sm font-medium mb-2">{{ __('Statement file') }}</label>
                <input type="file" wire:model="upload" accept=".csv,.xlsx,.xls,.ofx,.qfx,.qbo,.pdf" class="block w-full text-sm" />
                <div wire:loading wire:target="upload" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>
                @error('upload') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <flux:button variant="primary" icon="arrow-up-tray" wire:click="startImport" :disabled="! $upload">
                {{ __('Upload & analyze') }}
            </flux:button>
        </div>
    @elseif ($import->status->isProcessing())
        {{-- Parsing / matching --}}
        <div class="max-w-2xl space-y-4 rounded-lg border border-border bg-card p-6" wire:poll.1500ms="tick">
            <flux:callout icon="arrow-path">
                <flux:callout.heading>{{ __('Analyzing your statement…') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Parsing the file and matching it against your ledger. This page updates automatically.') }}</flux:callout.text>
            </flux:callout>
            <flux:text class="text-xs text-muted-foreground">{{ __('A queue worker must be running for large or PDF statements.') }}</flux:text>
        </div>
    @elseif ($import->status === \App\Enums\BankStatementImportStatus::Failed)
        <div class="max-w-2xl space-y-4 rounded-lg border border-rose-300 bg-card p-6">
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('We could not read this statement') }}</flux:callout.heading>
                <flux:callout.text>{{ $import->error_message }}</flux:callout.text>
            </flux:callout>
            <flux:button variant="ghost" wire:click="discard">{{ __('Start over') }}</flux:button>
        </div>
    @elseif ($import->status === \App\Enums\BankStatementImportStatus::NeedsMapping)
        {{-- STEP 2 — column mapping --}}
        @php $headers = $import->parse_meta['headers'] ?? []; @endphp
        <div class="max-w-3xl space-y-5 rounded-lg border border-border bg-card p-6">
            <div>
                <flux:heading size="lg">{{ __('Map your columns') }}</flux:heading>
                <flux:subheading>{{ __('Tell us which column is which. We will remember it for next time if you save a profile.') }}</flux:subheading>
            </div>

            @if ($import->parse_meta['ai_unavailable'] ?? false)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('AI assist is temporarily unavailable') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('We could not reach the AI service, so map the columns below — or come back and try again in a few minutes.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:radio.group wire:model.live="mapping.amountMode" :label="__('Amount columns')" variant="segmented">
                <flux:radio value="single">{{ __('One signed amount') }}</flux:radio>
                <flux:radio value="debit_credit">{{ __('Separate money in / out') }}</flux:radio>
            </flux:radio.group>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="mapping.dateColumn" :label="__('Date column')">
                    @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                </flux:select>
                <flux:select wire:model="mapping.descriptionColumn" :label="__('Description column')">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                </flux:select>

                @if (($mapping['amountMode'] ?? 'single') === 'single')
                    <flux:select wire:model="mapping.amountColumn" :label="__('Amount column')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model="mapping.debitColumn" :label="__('Money out (debit)')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                    </flux:select>
                    <flux:select wire:model="mapping.creditColumn" :label="__('Money in (credit)')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model="mapping.balanceColumn" :label="__('Running balance (optional)')">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($headers as $h) <flux:select.option :value="$h">{{ $h }}</flux:select.option> @endforeach
                </flux:select>
                <flux:select wire:model="mapping.dateFormat" :label="__('Date format')">
                    <flux:select.option value="Y-m-d">2026-01-31 (Y-m-d)</flux:select.option>
                    <flux:select.option value="m/d/Y">01/31/2026 (m/d/Y)</flux:select.option>
                    <flux:select.option value="d/m/Y">31/01/2026 (d/m/Y)</flux:select.option>
                    <flux:select.option value="d-M-Y">31-Jan-2026 (d-M-Y)</flux:select.option>
                    <flux:select.option value="M d, Y">Jan 31, 2026 (M d, Y)</flux:select.option>
                </flux:select>
            </div>

            @if (($mapping['amountMode'] ?? 'single') === 'single')
                <flux:checkbox wire:model="mapping.flipSign" :label="__('This column is positive for withdrawals (flip the sign)')" />
            @endif

            <div class="flex items-center gap-3 border-t border-border pt-4">
                <flux:checkbox wire:model.live="saveProfile" :label="__('Remember this mapping')" />
                @if ($saveProfile)
                    <flux:input wire:model="profileName" :placeholder="__('e.g. BMO Chequing CSV')" class="max-w-xs" />
                @endif
            </div>

            <div class="flex gap-2">
                <flux:button variant="primary" wire:click="applyMapping">{{ __('Apply mapping') }}</flux:button>
                <flux:button variant="ghost" wire:click="discard">{{ __('Cancel') }}</flux:button>
            </div>
        </div>
    @else
        {{-- STEP 3 — review --}}
        @php
            $lines = $import->lines;
            $matched = $lines->where('match_status', \App\Enums\StatementLineMatchStatus::Matched)->count();
            $added = $lines->where('match_status', \App\Enums\StatementLineMatchStatus::Created)->count();
            $dupes = $lines->where('match_status', \App\Enums\StatementLineMatchStatus::Duplicate)->count();
            $suggestedCount = $lines->filter(fn ($l) => $l->hasUnconfirmedSuggestion())->count();
        @endphp

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted p-3 text-sm">
            <div>
                <span class="font-semibold">{{ $import->original_filename }}</span>
                <span class="ml-3 text-muted-foreground">
                    {{ __(':n transactions', ['n' => $lines->count()]) }} ·
                    {{ __(':n matched', ['n' => $matched]) }} ·
                    {{ __(':n to add', ['n' => $added]) }} ·
                    @if ($suggestedCount > 0)
                        <span class="text-amber-600 dark:text-amber-400" data-test="import-suggested-count">{{ __(':n suggested', ['n' => $suggestedCount]) }}</span> ·
                    @endif
                    {{ __(':n duplicate', ['n' => $dupes]) }}
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($suggestedCount > 0)
                    <flux:button size="sm" variant="ghost" icon="check-badge" wire:click="confirmAllSuggestions" data-test="confirm-all-suggestions">{{ __('Confirm all suggestions (:n)', ['n' => $suggestedCount]) }}</flux:button>
                    <span class="text-xs text-amber-600 dark:text-amber-400" data-test="import-unconfirmed-warning">{{ __(':n unconfirmed', ['n' => $suggestedCount]) }}</span>
                @endif
                <flux:button size="sm" variant="ghost" wire:click="discard" wire:confirm="{{ __('Discard this import?') }}">{{ __('Discard') }}</flux:button>
                <flux:button size="sm" variant="primary" icon="check-circle" wire:click="commitImport" data-test="commit-import">{{ __('Import & reconcile') }}</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted text-xs uppercase text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($lines as $line)
                        @php
                            $status = $line->match_status;
                            $state = $this->rowState($line);
                            $editing = ($editingLines[$line->id] ?? false) === true;
                            $contactId = $this->lineContactId($line);
                            $contactName = $contactId ? ($this->contactNames[$contactId] ?? null) : null;
                            $contactState = $this->lineContactState($line->id);
                            $candidates = $this->billCandidates[$line->id] ?? null;
                            $coveringRule = $this->ruleCoveredLines[$line->id] ?? null;
                            $badge = match ($status) {
                                \App\Enums\StatementLineMatchStatus::Matched => 'green',
                                \App\Enums\StatementLineMatchStatus::Created => 'blue',
                                \App\Enums\StatementLineMatchStatus::Suggested => 'amber',
                                \App\Enums\StatementLineMatchStatus::Duplicate => 'zinc',
                                \App\Enums\StatementLineMatchStatus::Ignored => 'zinc',
                                default => 'orange',
                            };
                            $sourceIcon = match ($line->suggestion_source) {
                                \App\Enums\StatementSuggestionSource::Rule => 'bolt',
                                \App\Enums\StatementSuggestionSource::History => 'clock',
                                \App\Enums\StatementSuggestionSource::OpenBill => 'document-text',
                                \App\Enums\StatementSuggestionSource::ContactDefault => 'user',
                                \App\Enums\StatementSuggestionSource::Ai => 'sparkles',
                                default => null,
                            };
                        @endphp
                        <tr wire:key="line-{{ $line->id }}" data-test="line-state-{{ $line->id }}" data-state="{{ $state }}" @class(['opacity-50' => $state === 'duplicate' || $state === 'ignored'])>
                            <td class="px-3 py-2 whitespace-nowrap align-top">{{ $line->txn_date->toDateString() }}</td>
                            <td class="px-3 py-2 align-top text-muted-foreground">{{ $line->description }}</td>
                            <td class="px-3 py-2 text-right font-mono align-top @if ($line->amount_cents < 0) text-rose-600 @else text-emerald-600 @endif">
                                {{ $this->money($line->amount_cents) }}
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if ($state === 'suggested')
                                    <flux:badge size="sm" color="violet" :icon="$sourceIcon">{{ __('Suggested') }}</flux:badge>
                                @elseif ($state === 'possible-match')
                                    <flux:badge size="sm" color="amber">{{ __('Possible match') }}</flux:badge>
                                @elseif ($state === 'confirmed')
                                    <flux:badge size="sm" color="blue" icon="check">{{ $status->label() }}</flux:badge>
                                @else
                                    <flux:badge size="sm" :color="$badge">{{ $status->label() }}</flux:badge>
                                @endif
                                @if ($line->match_reason)
                                    <div class="mt-1 max-w-[18rem] text-xs text-muted-foreground" data-test="line-reason-{{ $line->id }}">{{ $line->match_reason }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if ($state === 'duplicate')
                                    <span class="text-xs text-muted-foreground">{{ __('Skipped') }}</span>
                                @elseif ($state === 'ignored')
                                    <flux:button size="xs" variant="ghost" wire:click="restore({{ $line->id }})">{{ __('Undo') }}</flux:button>
                                @elseif ($state === 'possible-match')
                                    <div class="flex items-center gap-1">
                                        <flux:button size="xs" variant="primary" wire:click="confirm({{ $line->id }})">{{ __('Confirm') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __('Skip') }}</flux:button>
                                    </div>
                                @elseif ($state === 'matched')
                                    <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __("Don't clear") }}</flux:button>
                                @elseif ($state === 'suggested' && ! $editing)
                                    {{-- Pre-filled by a rule, history, an open bill, a vendor default or AI: nothing posts until confirmed --}}
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs" data-test="line-summary-{{ $line->id }}">
                                            @if ($line->suggestedAccount)
                                                {{ $line->suggestedAccount->code }} — {{ $line->suggestedAccount->name }}
                                            @endif
                                            @if ($contactName)
                                                · {{ $contactName }}
                                            @endif
                                            @if ($line->isOutflow() && $this->lineTaxLabel($line) !== '')
                                                · {{ $this->lineTaxLabel($line) }}
                                            @endif
                                            @if ($line->suggested_bill_id)
                                                · {{ __('Pay bill :no', ['no' => $line->suggestedBill?->bill_no ?? '#'.$line->suggested_bill_id]) }}
                                            @elseif ($this->allocationLabel($line))
                                                · {{ $this->allocationLabel($line) }}
                                            @endif
                                        </span>
                                        <flux:button size="xs" variant="primary" icon="check" wire:click="confirmSuggestion({{ $line->id }})" data-test="line-confirm-{{ $line->id }}">{{ __('Confirm') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="editSuggestion({{ $line->id }})" data-test="line-change-{{ $line->id }}">{{ __('Change') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __('Skip') }}</flux:button>
                                    </div>
                                @else
                                    {{-- Unmatched, confirmed, or a suggestion being changed: the full controls --}}
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:select size="sm" wire:model.live="lineCategory.{{ $line->id }}" class="min-w-[220px]" data-test="line-category-{{ $line->id }}">
                                            <flux:select.option value="">{{ __('Add to…') }}</flux:select.option>
                                            @foreach ($this->categoryOptions as $opt)
                                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                            @endforeach
                                        </flux:select>

                                        @if ($line->isOutflow())
                                            @php($taxIds = array_values(array_filter($this->lineTaxIds($line))))
                                            <flux:dropdown>
                                                <flux:button size="sm" variant="outline" icon:trailing="chevron-down" class="font-normal" data-test="line-tax-{{ $line->id }}">
                                                    {{ $this->lineTaxLabel($line) ?: __('No tax') }}
                                                </flux:button>
                                                <flux:menu>
                                                    <flux:menu.checkbox.group wire:model.live="lineTax.{{ $line->id }}">
                                                        @foreach ($this->purchaseTaxCodes as $code)
                                                            <flux:menu.checkbox value="{{ $code->id }}" :disabled="count($taxIds) === 2 && ! in_array($code->id, $taxIds)" keep-open>{{ $code->code }}</flux:menu.checkbox>
                                                        @endforeach
                                                    </flux:menu.checkbox.group>
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endif

                                        <x-statement-contact-combo
                                            :line-id="$line->id"
                                            :add-label="$line->isInflow() ? 'customer' : 'vendor'"
                                            :options="$this->lineContactOptions($line->id)"
                                            :selected-id="$contactId"
                                            :selected-name="$contactName"
                                            :query="$contactState['query']"
                                            :creating="$contactState['creating']"
                                            :new-name="$contactState['new_name']"
                                        />

                                        @php($allocationLabel = $this->allocationLabel($line))
                                        @if (($candidates && $candidates->isNotEmpty()) || $allocationLabel)
                                            <flux:select size="sm" wire:model.live="lineBill.{{ $line->id }}" class="min-w-[220px]" data-test="line-record-as-{{ $line->id }}">
                                                <flux:select.option value="">{{ __('Record as expense') }}</flux:select.option>
                                                @if ($allocationLabel)
                                                    <flux:select.option value="allocations">{{ $allocationLabel }}</flux:select.option>
                                                @endif
                                                @foreach ($candidates ?? [] as $bill)
                                                    <flux:select.option :value="$bill->id">
                                                        {{ __('Pay bill :no (:amount · due :date)', [
                                                            'no' => $bill->bill_no ?: '#'.$bill->id,
                                                            'amount' => $this->money($bill->balanceCents()),
                                                            'date' => $bill->due_date ? \Carbon\CarbonImmutable::parse($bill->due_date)->isoFormat('MMM D') : '—',
                                                        ]) }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        @endif

                                        @if ($line->isOutflow() && $contactId && ($this->openBillCounts[$contactId] ?? 0) > 0)
                                            <flux:button size="xs" variant="ghost" icon="document-text" wire:click="openPayBills({{ $line->id }})" data-test="line-pay-bills-{{ $line->id }}">{{ __('Pay bills…') }}</flux:button>
                                        @endif

                                        @if (($lineCategory[$line->id] ?? '') !== '')
                                            @if ($coveringRule)
                                                <flux:tooltip :content="__('Rule “:name” already covers this — edit it under Bank rules.', ['name' => $coveringRule->name])">
                                                    <flux:button size="xs" variant="ghost" icon="bolt" disabled data-test="line-rule-exists-{{ $line->id }}" />
                                                </flux:tooltip>
                                            @else
                                                <flux:tooltip :content="__('Always do this — create a rule for this payee')">
                                                    <flux:button size="xs" variant="ghost" icon="bolt" wire:click="createRule({{ $line->id }})" data-test="line-make-rule-{{ $line->id }}" />
                                                </flux:tooltip>
                                            @endif
                                        @endif

                                        @if ($state === 'suggested')
                                            <flux:button size="xs" variant="primary" icon="check" wire:click="confirmSuggestion({{ $line->id }})" data-test="line-confirm-{{ $line->id }}">{{ __('Confirm') }}</flux:button>
                                        @endif
                                        <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __('Skip') }}</flux:button>
                                    </div>
                                    @if (($lineHint[$line->id] ?? '') !== '')
                                        <div class="mt-1 text-xs text-muted-foreground" data-test="line-hint-{{ $line->id }}">{{ $lineHint[$line->id] }}</div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:text class="mt-3 text-xs text-muted-foreground">
            {{ __('Matched and added lines will be pre-ticked on the reconciliation screen, where you can finish and post. Suggested lines are only added once you confirm them.') }}
        </flux:text>

        <flux:modal name="unconfirmed-suggestions" class="max-w-md" data-test="unconfirmed-modal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __("Some suggestions aren't confirmed") }}</flux:heading>
                    <flux:subheading>
                        {{ trans_choice('{1} :count suggested line has not been confirmed. Unconfirmed lines are not added to your books now — they wait in For Review.|[2,*] :count suggested lines have not been confirmed. Unconfirmed lines are not added to your books now — they wait in For Review.', $unconfirmedCount, ['count' => $unconfirmedCount]) }}
                    </flux:subheading>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="filled" wire:click="commitWithoutSuggestions" data-test="modal-commit-anyway">{{ __('Import without them') }}</flux:button>
                    <flux:button variant="primary" icon="check-badge" wire:click="confirmAllAndCommit" data-test="modal-confirm-all">{{ __('Confirm all & import') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="pay-bills" class="md:w-[680px]" wire:close="cancelPayBills" data-test="pay-bills-modal">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Pay bills from this transaction') }}</flux:heading>
                <flux:subheading>{{ __('Apply :amount across the open bills below. The amounts must add up to the transaction; a bill may be paid in part.', ['amount' => number_format($payBillsTargetCents / 100, 2)]) }}</flux:subheading>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted text-xs uppercase text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ __('Bill #') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($payBillsRows as $i => $row)
                                <tr wire:key="pay-bill-{{ $row['bill_id'] }}" data-test="pay-bills-row">
                                    <td class="px-3 py-2">{{ $row['bill_no'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($row['type'] === \App\Enums\BillType::Reimbursement->value)
                                            <flux:badge size="sm" color="zinc">{{ __('Reimbursement') }}</flux:badge>
                                        @else
                                            <span class="text-muted-foreground">{{ __('Bill') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ $row['due_date'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <x-amount-input model="payBillsRows.{{ $i }}.apply" modifiers=".live.debounce.300ms" size="sm" placeholder="0.00" class="ml-auto w-32 text-right" data-test="pay-bills-apply" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @error('payBillsRows')
                    <flux:text class="text-red-600" data-test="pay-bills-error">{{ $message }}</flux:text>
                @enderror

                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span class="font-mono {{ $this->payBillsTotal() === $payBillsTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="pay-bills-total">
                        {{ __('Allocated :a of :t', ['a' => number_format($this->payBillsTotal() / 100, 2), 't' => number_format($payBillsTargetCents / 100, 2)]) }}
                    </span>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="cancelPayBills" data-test="pay-bills-cancel">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="savePayBills" :disabled="$this->payBillsTotal() !== $payBillsTargetCents" data-test="pay-bills-save">{{ __('Save') }}</flux:button>
                    </div>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
