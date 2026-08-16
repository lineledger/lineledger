<?php

use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Jobs\ProcessBankStatementImport;
use App\Models\Account;
use App\Models\BankImportProfile;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Services\AttachmentService;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import statement')] class extends Component {
    use WithFileUploads;

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
        $this->reset('importId', 'upload', 'mapping', 'lineCategory', 'saveProfile', 'profileName');
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
        return $this->importId ? BankStatementImport::with('lines')->find($this->importId) : null;
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
            $this->seedCategories($import);
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

    protected function seedCategories(BankStatementImport $import): void
    {
        $this->lineCategory = $import->lines
            ->mapWithKeys(fn (BankStatementLine $l) => [$l->id => $l->suggested_account_id ?? ''])
            ->all();
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
        } else {
            $line->forceFill([
                'match_status' => StatementLineMatchStatus::Created->value,
                'suggested_account_id' => (int) $value,
            ])->save();
        }

        unset($this->import);
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
            ])->save();
            $this->lineCategory[$lineId] = '';
            unset($this->import);
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

    public function commitImport(): void
    {
        $import = $this->import;

        if (! $import) {
            return;
        }

        try {
            $rec = app(StatementImportCommitter::class)->commit($import, Auth::id());
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Statement imported — review and finish the reconciliation.'));

        $this->redirectRoute('banking.reconcile', ['company' => $this->company->slug, 'account' => $import->account_id], navigate: true);
    }

    public function discard(): void
    {
        $import = $this->import;

        if ($import && ! $import->isCommitted()) {
            $import->delete();
        }

        $this->reset('importId', 'upload', 'mapping', 'lineCategory', 'saveProfile', 'profileName');
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
        @endphp

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted p-3 text-sm">
            <div>
                <span class="font-semibold">{{ $import->original_filename }}</span>
                <span class="ml-3 text-muted-foreground">
                    {{ __(':n transactions', ['n' => $lines->count()]) }} ·
                    {{ __(':n matched', ['n' => $matched]) }} ·
                    {{ __(':n to add', ['n' => $added]) }} ·
                    {{ __(':n duplicate', ['n' => $dupes]) }}
                </span>
            </div>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="discard" wire:confirm="{{ __('Discard this import?') }}">{{ __('Discard') }}</flux:button>
                <flux:button size="sm" variant="primary" icon="check-circle" wire:click="commitImport">{{ __('Import & reconcile') }}</flux:button>
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
                        @php $status = $line->match_status; @endphp
                        <tr wire:key="line-{{ $line->id }}" @class(['opacity-50' => $status === \App\Enums\StatementLineMatchStatus::Duplicate || $status === \App\Enums\StatementLineMatchStatus::Ignored])>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $line->txn_date->toDateString() }}</td>
                            <td class="px-3 py-2 text-muted-foreground">{{ $line->description }}</td>
                            <td class="px-3 py-2 text-right font-mono @if ($line->amount_cents < 0) text-rose-600 @else text-emerald-600 @endif">
                                {{ $this->money($line->amount_cents) }}
                            </td>
                            <td class="px-3 py-2">
                                @php
                                    $badge = match ($status) {
                                        \App\Enums\StatementLineMatchStatus::Matched => 'green',
                                        \App\Enums\StatementLineMatchStatus::Created => 'blue',
                                        \App\Enums\StatementLineMatchStatus::Suggested => 'amber',
                                        \App\Enums\StatementLineMatchStatus::Duplicate => 'zinc',
                                        \App\Enums\StatementLineMatchStatus::Ignored => 'zinc',
                                        default => 'orange',
                                    };
                                @endphp
                                <flux:badge size="sm" :color="$badge">{{ $status->label() }}</flux:badge>
                                @if ($line->match_reason)
                                    <div class="text-xs text-muted-foreground">{{ $line->match_reason }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @if ($status === \App\Enums\StatementLineMatchStatus::Duplicate)
                                    <span class="text-xs text-muted-foreground">{{ __('Skipped') }}</span>
                                @elseif ($status === \App\Enums\StatementLineMatchStatus::Ignored)
                                    <flux:button size="xs" variant="ghost" wire:click="restore({{ $line->id }})">{{ __('Undo') }}</flux:button>
                                @elseif ($status === \App\Enums\StatementLineMatchStatus::Suggested)
                                    <div class="flex items-center gap-1">
                                        <flux:button size="xs" variant="primary" wire:click="confirm({{ $line->id }})">{{ __('Confirm') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __('Skip') }}</flux:button>
                                    </div>
                                @elseif ($status === \App\Enums\StatementLineMatchStatus::Matched)
                                    <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __("Don't clear") }}</flux:button>
                                @else
                                    {{-- Unmatched or Created: choose a category to add it --}}
                                    <div class="flex items-center gap-2">
                                        <flux:select size="sm" wire:model.live="lineCategory.{{ $line->id }}" class="min-w-[220px]">
                                            <flux:select.option value="">{{ __('Add to…') }}</flux:select.option>
                                            @foreach ($this->categoryOptions as $opt)
                                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button size="xs" variant="ghost" wire:click="ignore({{ $line->id }})">{{ __('Skip') }}</flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:text class="mt-3 text-xs text-muted-foreground">
            {{ __('Matched and added lines will be pre-ticked on the reconciliation screen, where you can finish and post.') }}
        </flux:text>
    @endif
</section>
