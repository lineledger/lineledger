<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\JournalEntryTemplateLine;
use App\Models\Location;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use App\Support\Reporting\SourceLinkResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Journal entry')] class extends Component
{
    public Company $company;

    public ?JournalEntry $entry = null;

    public string $entryNo = '';

    public string $entryDate = '';

    public string $memo = '';

    public bool $isPosted = false;

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    /**
     * Selected template to pull lines from when creating a new entry. Only shown
     * on create; ignored on save (it is purely a population trigger).
     */
    public ?int $template_id = null;

    /**
     * Name entered in the "Save as template" modal.
     */
    public string $template_name = '';

    /**
     * @var array<int, array{account_id: ?int, contact_id: ?int, contact_query: string, contact_creating: bool, new_contact_name: string, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int}>
     */
    public array $lines = [];

    public function mount(Company $company, ?JournalEntry $entry = null): void
    {
        $this->company = $company;

        if ($entry && $entry->exists) {
            if ($entry->source_type !== null) {
                $resolver = app(SourceLinkResolver::class);
                $url = $resolver->urlFor($entry, $company)
                    ?? route('journal.show', ['company' => $company->slug, 'entry' => $entry->id]);
                Flux::toast(text: __('This entry is managed by :source. Edit it there.', ['source' => $resolver->label($entry)]));
                $this->redirect($url, navigate: true);

                return;
            }

            $this->entry = $entry;
            $this->isPosted = $entry->isPosted();
            $this->entryNo = $entry->entry_no;
            $this->entryDate = $entry->entry_date->toDateString();
            $this->memo = $entry->memo ?? '';
            $this->lines = $this->mapEntryLines($entry);

            return;
        }

        $this->entryDate = $this->company->currentDateTime()->toDateString();
        $this->entryNo = app(EntryNumberGenerator::class)->next($company);
        $this->lines = [$this->emptyLine(), $this->emptyLine()];

        if ($this->duplicateFromId) {
            $this->prefillFrom($this->duplicateFromId);
        }
    }

    /**
     * Copy a source entry's memo and lines into a fresh, unsaved draft. Ignores
     * sources from another company. The new entry keeps the today's date and
     * generated entry number set up for a blank draft.
     */
    protected function prefillFrom(int $sourceId): void
    {
        $source = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->find($sourceId);

        if (! $source) {
            return;
        }

        $this->memo = $source->memo ?? '';
        $this->lines = $this->mapEntryLines($source);
    }

    /**
     * @return array<int, array{account_id: ?int, contact_id: ?int, contact_query: string, contact_creating: bool, new_contact_name: string, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int}>
     */
    protected function mapEntryLines(JournalEntry $entry): array
    {
        return $entry->lines->map(fn ($l) => [
            ...$this->emptyLine(),
            'account_id' => $l->account_id,
            'contact_id' => $l->contact_id,
            'debit' => $l->debit_cents > 0 ? Money::fromCents($l->debit_cents)->toDecimalString() : '',
            'credit' => $l->credit_cents > 0 ? Money::fromCents($l->credit_cents)->toDecimalString() : '',
            'memo' => $l->memo,
            'tax_code_id' => $l->tax_code_id,
            'class_id' => $l->class_id,
            'location_id' => $l->location_id,
            'fund_id' => $l->fund_id,
        ])->all();
    }

    /**
     * @return array{account_id: ?int, contact_id: ?int, contact_query: string, contact_creating: bool, new_contact_name: string, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int}
     */
    protected function emptyLine(): array
    {
        return [
            'account_id' => null,
            'contact_id' => null,
            'contact_query' => '',
            'contact_creating' => false,
            'new_contact_name' => '',
            'debit' => '',
            'credit' => '',
            'memo' => null,
            'tax_code_id' => null,
            'class_id' => null,
            'location_id' => null,
            'fund_id' => null,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * Map of account id => required contact role ('customer' for AR, 'vendor' for AP).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function contactRequiringAccounts(): array
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('subtype', [
                AccountSubtype::AccountsReceivable->value,
                AccountSubtype::AccountsPayable->value,
            ])
            ->get(['id', 'subtype'])
            ->mapWithKeys(fn (Account $a) => [
                $a->id => $a->subtype === AccountSubtype::AccountsReceivable ? 'customer' : 'vendor',
            ])
            ->all();
    }

    /**
     * Contacts matching a line's current search, scoped to the role its account requires.
     *
     * @return Collection<int, Contact>
     */
    public function lineContactOptions(int $index): Collection
    {
        $role = $this->contactRequiringAccounts[(int) ($this->lines[$index]['account_id'] ?? 0)] ?? null;

        if ($role === null) {
            return collect();
        }

        $query = Contact::query()
            ->where($role === 'customer' ? 'is_customer' : 'is_vendor', true)
            ->where('is_active', true);

        $search = trim((string) ($this->lines[$index]['contact_query'] ?? ''));

        if ($search !== '') {
            $query->where('display_name', 'like', '%'.$search.'%');
        }

        return $query->orderBy('display_name')->limit(50)->get(['id', 'display_name']);
    }

    public function lineContactName(int $index): ?string
    {
        $id = $this->lines[$index]['contact_id'] ?? null;

        return $id ? Contact::query()->where('id', $id)->value('display_name') : null;
    }

    public function selectLineContact(int $index, int $id): void
    {
        $this->lines[$index]['contact_id'] = $id;
        $this->lines[$index]['contact_creating'] = false;
        $this->lines[$index]['new_contact_name'] = '';
        $this->lines[$index]['contact_query'] = '';
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    public function startNewLineContact(int $index): void
    {
        $this->lines[$index]['new_contact_name'] = trim((string) ($this->lines[$index]['contact_query'] ?? ''));
        $this->lines[$index]['contact_creating'] = true;
        $this->lines[$index]['contact_id'] = null;
        $this->lines[$index]['contact_query'] = '';
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    public function clearLineContact(int $index): void
    {
        $this->lines[$index]['contact_id'] = null;
        $this->lines[$index]['contact_creating'] = false;
        $this->lines[$index]['new_contact_name'] = '';
        $this->lines[$index]['contact_query'] = '';
        $this->resetErrorBag(["lines.{$index}.contact_id", "lines.{$index}.new_contact_name"]);
    }

    /**
     * Reset the contact picker when a line's account changes to one that no longer
     * requires it, and fill a blank tax code from the account's default.
     */
    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null || ! str_ends_with($key, '.account_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];

        // Picking an account fills a blank tax code from the account's default —
        // never overwriting one already on the line. The tag carries no amounts,
        // so nothing is recalculated.
        if ($value && empty($this->lines[$index]['tax_code_id'])) {
            $this->lines[$index]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
        }

        if (! isset($this->contactRequiringAccounts[(int) $value])) {
            $this->lines[$index]['contact_id'] = null;
            $this->lines[$index]['contact_creating'] = false;
            $this->lines[$index]['new_contact_name'] = '';
            $this->lines[$index]['contact_query'] = '';
        }
    }

    #[Computed]
    public function templateOptions()
    {
        return JournalEntryTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Pull a template's lines onto the form when one is picked. Only acts while
     * creating (templates are hidden when editing). Replaces the seeded blank
     * lines if they are all still pristine, otherwise appends.
     */
    public function updatedTemplateId(?int $value): void
    {
        if ($this->entry?->id || ! $value) {
            return;
        }

        $template = JournalEntryTemplate::with('lines')->find($value);

        if (! $template) {
            return;
        }

        $mapped = $template->lines->map(fn (JournalEntryTemplateLine $tl) => $this->lineFromTemplate($tl))->all();

        if ($mapped === []) {
            return;
        }

        if (collect($this->lines)->every(fn (array $l): bool => $this->isEmptyLine($l))) {
            $this->lines = $mapped;
        } else {
            $this->lines = array_merge($this->lines, $mapped);
        }

        $this->lines = array_values($this->lines);
    }

    /**
     * Map a template line into the form's $lines shape (mirrors emptyLine()).
     *
     * @return array<string, mixed>
     */
    protected function lineFromTemplate(JournalEntryTemplateLine $tl): array
    {
        return [
            ...$this->emptyLine(),
            'account_id' => $tl->account_id,
            'debit' => $tl->debit_cents > 0 ? Money::fromCents($tl->debit_cents)->toDecimalString() : '',
            'credit' => $tl->credit_cents > 0 ? Money::fromCents($tl->credit_cents)->toDecimalString() : '',
            'memo' => $tl->memo,
            'tax_code_id' => $tl->tax_code_id,
            'class_id' => $tl->class_id,
            'location_id' => $tl->location_id,
            'fund_id' => $tl->fund_id,
        ];
    }

    /**
     * Whether a line is still a pristine blank — used to decide replace-vs-append
     * when applying a template and which lines to skip when saving as a template.
     *
     * @param  array<string, mixed>  $line
     */
    protected function isEmptyLine(array $line): bool
    {
        return empty($line['account_id'])
            && (Money::tryFromString((string) ($line['debit'] ?? ''))?->cents ?? 0) === 0
            && (Money::tryFromString((string) ($line['credit'] ?? ''))?->cents ?? 0) === 0
            && trim((string) ($line['memo'] ?? '')) === '';
    }

    /**
     * Capture the current lines as a reusable journal-entry template. Reuses the
     * same SaveJournalEntryTemplate action as the dedicated management page.
     */
    public function saveAsTemplate(): void
    {
        $validated = $this->validate(
            ['template_name' => ['required', 'string', 'max:255']],
            attributes: ['template_name' => __('template name')],
        );

        $lines = collect($this->lines)
            ->reject(fn (array $line): bool => $this->isEmptyLine($line))
            ->map(fn (array $line): array => [
                'account_id' => $line['account_id'] ?? null,
                'debit_cents' => Money::tryFromString((string) ($line['debit'] ?? ''))?->cents ?? 0,
                'credit_cents' => Money::tryFromString((string) ($line['credit'] ?? ''))?->cents ?? 0,
                'memo' => $line['memo'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'fund_id' => $line['fund_id'] ?? null,
            ])
            ->values()
            ->all();

        if ($lines === []) {
            $this->addError('template_name', __('Add at least one line before saving a template.'));

            return;
        }

        app(SaveJournalEntryTemplate::class)->handle([
            'name' => $validated['template_name'],
            'is_active' => true,
            'lines' => $lines,
        ]);

        Flux::modal('save-as-je-template')->close();
        $this->template_name = '';
        unset($this->templateOptions);
        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    #[Computed]
    public function totalDebitsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['debit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function totalCreditsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['credit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function differenceCents(): int
    {
        return $this->totalDebitsCents - $this->totalCreditsCents;
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function classificationOptions()
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function locationOptions()
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksClasses(): bool
    {
        return (bool) $this->company->features_classes;
    }

    #[Computed]
    public function tracksLocations(): bool
    {
        return (bool) $this->company->features_locations;
    }

    #[Computed]
    public function fundOptions()
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksFunds(): bool
    {
        return $this->company->tracksFunds();
    }

    #[Computed]
    public function dimensionColumns(): int
    {
        return (int) $this->tracksClasses + (int) $this->tracksLocations + (int) $this->tracksFunds;
    }

    public function saveDraft(): void
    {
        $this->persist(post: false);
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('journal.edit', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    public function postEntry(JournalPoster $poster): void
    {
        $this->persist(post: false);

        try {
            $poster->post($this->entry);
        } catch (UnbalancedJournalException|PeriodLockedException|ReconciliationLockedException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Entry posted.'));
        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    /**
     * Save edits to an already-posted entry in place. The entry stays posted, so it
     * must remain balanced and out of any locked period; affected account balances
     * are recomputed and the overwrite is recorded to the audit trail.
     */
    public function saveChanges(JournalPoster $poster): void
    {
        abort_unless($this->entry && $this->entry->isPosted(), 403);
        abort_if($this->entry->source_type !== null, 403);

        if ($this->differenceCents !== 0 || $this->totalDebitsCents === 0) {
            $this->addError('lines', __('A posted entry must balance before saving.'));

            return;
        }

        if ($this->company->isLockedFor(CarbonImmutable::parse($this->entryDate))) {
            $this->addError('lines', __('That date falls in a locked period.'));

            return;
        }

        $affectedAccountIds = $this->entry->lines->pluck('account_id')->all();

        $this->persist(post: false);

        $affectedAccountIds = array_merge($affectedAccountIds, $this->entry->lines->pluck('account_id')->all());
        $poster->recomputeAccounts($affectedAccountIds);

        $fresh = $this->entry->fresh('lines.account');

        app(AccountingAuditRecorder::class)->record(
            (int) $this->entry->company_id,
            AuditAction::JournalEntryUpdated,
            $this->entry,
            AccountingAuditRecorder::snapshotJournalEntry($fresh),
            $fresh,
        );

        Flux::toast(variant: 'success', text: __('Entry updated.'));
        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    private function persist(bool $post): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'entryNo' => ['required', 'string', 'max:40'],
            'entryDate' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.contact_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'string', new MoneyString],
            'lines.*.credit' => ['nullable', 'string', new MoneyString],
            'lines.*.memo' => ['nullable', 'string'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'lines.*.fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('company_id', $companyId)],
        ]);

        $this->resolveNewLineContacts();
        $this->validateLineContacts();

        $lines = [];
        foreach ($validated['lines'] as $i => $line) {
            $requiresContact = isset($this->contactRequiringAccounts[(int) $line['account_id']]);

            $lines[] = [
                'account_id' => $line['account_id'],
                'contact_id' => $requiresContact ? ($this->lines[$i]['contact_id'] ?: null) : null,
                'debit_cents' => $line['debit'] !== '' ? Money::fromString($line['debit'])->cents : 0,
                'credit_cents' => $line['credit'] !== '' ? Money::fromString($line['credit'])->cents : 0,
                'memo' => $line['memo'] ?: null,
                'tax_code_id' => ($this->lines[$i]['tax_code_id'] ?? null) ?: null,
                'class_id' => $this->lines[$i]['class_id'] ?? null,
                'location_id' => $this->lines[$i]['location_id'] ?? null,
                'fund_id' => $this->lines[$i]['fund_id'] ?? null,
            ];
        }

        $this->entry = app(SaveJournalEntry::class)->handle([
            'entry_no' => $validated['entryNo'],
            'entry_date' => $validated['entryDate'],
            'memo' => $validated['memo'] ?: null,
            'lines' => $lines,
        ], $this->entry);
    }

    /**
     * Create any "add new" contacts typed into the line pickers before validation runs.
     */
    private function resolveNewLineContacts(): void
    {
        foreach ($this->lines as $i => $line) {
            if (empty($line['contact_creating'])) {
                continue;
            }

            $role = $this->contactRequiringAccounts[(int) ($line['account_id'] ?? 0)] ?? null;

            if ($role === null || ! $this->lineHasAmount($line)) {
                continue;
            }

            $this->validate(
                ["lines.{$i}.new_contact_name" => ['required', 'string', 'max:255']],
                attributes: ["lines.{$i}.new_contact_name" => $role === 'customer' ? __('customer name') : __('vendor name')],
            );

            $contact = Contact::create([
                'display_name' => trim((string) $line['new_contact_name']),
                'is_customer' => $role === 'customer',
                'is_vendor' => $role === 'vendor',
                'is_active' => true,
            ]);

            $this->lines[$i]['contact_id'] = $contact->id;
            $this->lines[$i]['contact_creating'] = false;
            $this->lines[$i]['new_contact_name'] = '';
            $this->lines[$i]['contact_query'] = '';
        }
    }

    /**
     * @param  array{debit: string, credit: string}  $line
     */
    private function lineHasAmount(array $line): bool
    {
        $debit = Money::tryFromString((string) $line['debit'])?->cents ?? 0;
        $credit = Money::tryFromString((string) $line['credit'])?->cents ?? 0;

        return $debit !== 0 || $credit !== 0;
    }

    /**
     * Require a customer on Accounts Receivable lines and a vendor on Accounts Payable lines.
     */
    private function validateLineContacts(): void
    {
        $errors = [];

        foreach ($this->lines as $i => $line) {
            if (! $this->lineHasAmount($line)) {
                continue;
            }

            $role = $this->contactRequiringAccounts[(int) ($line['account_id'] ?? 0)] ?? null;

            if ($role === null) {
                continue;
            }

            $contactId = $line['contact_id'] ?? null;

            if (! $contactId) {
                $errors["lines.{$i}.contact_id"] = $role === 'customer'
                    ? __('Select a customer for the Accounts Receivable line.')
                    : __('Select a vendor for the Accounts Payable line.');

                continue;
            }

            $column = $role === 'customer' ? 'is_customer' : 'is_vendor';

            $exists = Contact::query()
                ->whereKey($contactId)
                ->where($column, true)
                ->exists();

            if (! $exists) {
                $errors["lines.{$i}.contact_id"] = $role === 'customer'
                    ? __('Select a valid customer for the Accounts Receivable line.')
                    : __('Select a valid vendor for the Accounts Payable line.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ $entry?->id ? __('Edit journal entry') : __('New journal entry') }}</flux:heading>

    @if ($isPosted)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            {{ __('This entry is posted. Saving overwrites it in place and changes already-reported balances.') }}
        </flux:callout>
    @endif

    <form wire:submit="{{ $isPosted ? 'saveChanges' : 'postEntry' }}" class="space-y-6">
        @unless ($entry?->id)
            @if ($this->templateOptions->isNotEmpty())
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:select wire:model.live="template_id" :label="__('Template')" :description="__('Fill the lines below from a saved template.')" data-test="journal-entry-template-picker">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach ($this->templateOptions as $tpl)
                            <flux:select.option :value="$tpl->id">{{ $tpl->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        @endunless

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="entryNo" :label="__('Entry #')" required data-test="entry-no-input" />
            <flux:input type="date" wire:model="entryDate" :label="__('Date')" required data-test="entry-date-input" />
            <flux:input wire:model="memo" :label="__('Memo')" data-test="entry-memo-input" />
        </div>

        @php($lineGrid = 'lg:grid lg:grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_minmax(0,1.1fr)_2.75rem] lg:items-start lg:gap-3')
        <div class="overflow-hidden rounded-lg border border-border text-sm">
            {{-- Column headers (desktop): the line fields below align to these columns. --}}
            <div class="{{ $lineGrid }} hidden bg-muted px-3 py-2 font-medium text-muted-foreground">
                <div>{{ __('Account') }}</div>
                <div>{{ __('Tax code') }}</div>
                <div class="text-right">{{ __('Debit') }}</div>
                <div class="text-right">{{ __('Credit') }}</div>
                <div>{{ __('Line memo') }}</div>
                <div></div>
            </div>

            <div class="divide-y divide-border">
                @foreach ($lines as $i => $line)
                    <div wire:key="line-{{ $i }}" data-test="entry-line-row" class="space-y-3 p-3">
                        {{-- Tier 1: account, debit, credit, memo, remove --}}
                        <div class="{{ $lineGrid }} grid grid-cols-1 gap-3">
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                    <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                @php($contactRole = $this->contactRequiringAccounts[(int) ($line['account_id'] ?? 0)] ?? null)
                                @if ($contactRole === 'customer')
                                    <x-journal-contact-combo
                                        :index="$i"
                                        add-label="customer"
                                        :placeholder="__('Search or add a customer…')"
                                        :options="$this->lineContactOptions($i)"
                                        :selected-id="$line['contact_id']"
                                        :selected-name="$this->lineContactName($i)"
                                        :query="$line['contact_query']"
                                        :creating="$line['contact_creating']"
                                        data-test="line-customer-combo"
                                    />
                                @elseif ($contactRole === 'vendor')
                                    <x-journal-contact-combo
                                        :index="$i"
                                        add-label="vendor"
                                        :placeholder="__('Search or add a vendor…')"
                                        :options="$this->lineContactOptions($i)"
                                        :selected-id="$line['contact_id']"
                                        :selected-name="$this->lineContactName($i)"
                                        :query="$line['contact_query']"
                                        :creating="$line['contact_creating']"
                                        data-test="line-vendor-combo"
                                    />
                                @endif
                            </div>
                            <div>
                                {{-- A reporting tag only — no amounts are calculated from it. --}}
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax code') }}</span>
                                <flux:select wire:model="lines.{{ $i }}.tax_code_id" data-test="line-tax">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->taxCodeOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Debit') }}</span>
                                <x-amount-input model="lines.{{ $i }}.debit" class="lg:text-right" placeholder="0.00" data-test="line-debit" />
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Credit') }}</span>
                                <x-amount-input model="lines.{{ $i }}.credit" class="lg:text-right" placeholder="0.00" data-test="line-credit" />
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Line memo') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.memo" />
                            </div>
                            <div class="flex lg:justify-end lg:pt-1">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </div>
                        </div>

                        {{-- Tier 2: class, location & fund dimensions (only when tracked) --}}
                        @if ($this->tracksClasses || $this->tracksLocations || $this->tracksFunds)
                            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                @if ($this->tracksClasses)
                                    <flux:select wire:model="lines.{{ $i }}.class_id" :label="__('Class')" class="sm:w-56" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                                @if ($this->tracksLocations)
                                    <flux:select wire:model="lines.{{ $i }}.location_id" :label="__('Location')" class="sm:w-56" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                                @if ($this->tracksFunds)
                                    <flux:select wire:model="lines.{{ $i }}.fund_id" :label="__('Fund')" class="sm:w-56" data-test="line-fund">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->fundOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Totals (desktop): aligned to the debit/credit columns above. --}}
            <div class="{{ $lineGrid }} hidden border-t border-border bg-muted px-3 py-2">
                <div></div>
                <div class="text-right font-medium">{{ __('Totals') }}</div>
                <div class="text-right font-mono" data-test="total-debits">{{ number_format($this->totalDebitsCents / 100, 2) }}</div>
                <div class="text-right font-mono" data-test="total-credits">{{ number_format($this->totalCreditsCents / 100, 2) }}</div>
                <div class="text-muted-foreground" data-test="balance-indicator">
                    @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                        <span class="text-green-600">{{ __('Balanced') }}</span>
                    @elseif ($this->totalDebitsCents === 0 && $this->totalCreditsCents === 0)
                        {{ __('Enter amounts') }}
                    @else
                        <span class="text-amber-600">{{ __('Out of balance by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                    @endif
                </div>
                <div></div>
            </div>

            {{-- Totals (mobile) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Total debits') }}</span><span class="font-mono" data-test="total-debits-mobile">{{ number_format($this->totalDebitsCents / 100, 2) }}</span></div>
                <div class="flex justify-between"><span class="font-medium">{{ __('Total credits') }}</span><span class="font-mono" data-test="total-credits-mobile">{{ number_format($this->totalCreditsCents / 100, 2) }}</span></div>
                <div class="flex justify-between" data-test="balance-indicator-mobile">
                    <span class="font-medium">{{ __('Balance') }}</span>
                    <span class="text-muted-foreground">
                        @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                            <span class="text-green-600">{{ __('Balanced') }}</span>
                        @elseif ($this->totalDebitsCents === 0 && $this->totalCreditsCents === 0)
                            {{ __('Enter amounts') }}
                        @else
                            <span class="text-amber-600">{{ __('Out of balance by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">
                {{ __('Add line') }}
            </flux:button>

            @error('lines')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror

            <div class="flex gap-2">
                <flux:button variant="ghost" type="button" icon="document-duplicate" x-on:click="$flux.modal('save-as-je-template').show()" data-test="save-as-je-template-button">{{ __('Save as template') }}</flux:button>
                @if ($isPosted)
                    <flux:button variant="primary" type="submit" data-test="save-changes-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-entry-button">{{ __('Post entry') }}</flux:button>
                @endif
            </div>
        </div>
    </form>

    <flux:modal name="save-as-je-template" class="max-w-md">
        <form wire:submit="saveAsTemplate" class="space-y-6">
            <flux:heading size="lg">{{ __('Save as template') }}</flux:heading>
            <flux:text>{{ __('Save the current lines as a reusable template for future journal entries.') }}</flux:text>

            <flux:input wire:model="template_name" :label="__('Template name')" placeholder="{{ __('e.g. Monthly depreciation') }}" required data-test="save-as-je-template-name" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-as-je-template-confirm">{{ __('Save template') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
