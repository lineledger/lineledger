<?php

use App\Actions\Banking\SaveCheque;
use App\Actions\Contacts\UpdateContactAddress;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Livewire\Concerns\ManagesLineContacts;
use App\Livewire\Concerns\ManagesPayeeCombo;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Cheque;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Location;
use App\Models\PayrollCheque;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\AttachmentService;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Support\Accounting\ControlAccountRoles;
use App\Support\Banking\LastBankAccount;
use App\Support\Contacts\AddressLines;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Cheque')] class extends Component
{
    use GuardsEditLockedForm;
    use ManagesLineContacts;
    // clearPayee is extended below to clear the address with the payee; the
    // trait's own version is aliased so the override can still call it.
    use ManagesPayeeCombo {
        ManagesPayeeCombo::clearPayee as protected clearPayeeCombo;
    }
    use WithFileUploads;

    public Company $company;

    public ?Cheque $cheque = null;

    public ?int $bank_account_id = null;

    public string $cheque_no = '';

    public string $cheque_date = '';

    public ?int $payee_contact_id = null;

    public string $payee_name = '';

    public string $memo = '';

    /**
     * The address this cheque is mailed to. Seeded from the payee's record but
     * editable per cheque, and snapshotted onto the cheque when saved — see
     * SaveCheque::payeeAddress().
     */
    public string $payee_line1 = '';

    public string $payee_line2 = '';

    public string $payee_city = '';

    public string $payee_region = '';

    public string $payee_postal_code = '';

    public string $payee_country = '';

    /** Save path parked while the "update the payee's record too?" modal is open. */
    public ?string $pendingSaveAction = null;

    /** Set once the operator has answered that modal, so a retry never re-asks. */
    public bool $addressWriteBackAnswered = false;

    /**
     * @var array<int, array{account_id: ?int, contact_id: ?int, contact_query: string, contact_creating: bool, new_contact_name: string, description: string, amount: string, tax_code_id: ?int, tax_override: string, class_id: ?int, location_id: ?int, auto_tax_cents: int, tax_cents: int, total: int}>
     */
    public array $lines = [];

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    protected function editLockRecord(): ?Model
    {
        return $this->cheque;
    }

    public function mount(Company $company, ?Cheque $cheque = null): void
    {
        $this->company = $company;

        if ($cheque && $cheque->exists) {
            // A posted cheque is editable — saving reposts its GL entry in
            // place (see save()). Only a voided one is frozen.
            abort_if($cheque->status === ChequeStatus::Void, 403);

            $this->cheque = $cheque->load('lines');
            $this->bank_account_id = $cheque->bank_account_id;
            $this->cheque_no = $cheque->cheque_no;
            $this->cheque_date = $cheque->cheque_date->toDateString();
            $this->payee_contact_id = $cheque->payee_contact_id;
            $this->payee_name = $cheque->payee_name;
            $this->memo = $cheque->memo ?? '';

            $this->fillPayeeAddress([
                'line1' => $cheque->payee_line1,
                'line2' => $cheque->payee_line2,
                'city' => $cheque->payee_city,
                'region' => $cheque->payee_region,
                'postal_code' => $cheque->payee_postal_code,
                'country' => $cheque->payee_country,
            ]);

            $this->loadLinesFrom($cheque);
        } else {
            $this->cheque_date = $this->company->currentDateTime()->toDateString();

            // Reopen on the account this operator last worked in; fall back to
            // the lowest-numbered active bank on the very first cheque.
            $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
            $this->bank_account_id = LastBankAccount::recall($company, $this->bankAccounts) ?? $bank?->id;

            $this->cheque_no = $this->nextChequeNumber();
            $this->lines = [$this->emptyLine()];

            if ($this->duplicateFromId) {
                $this->prefillFrom($this->duplicateFromId);
            }
        }
    }

    /**
     * Copy a cheque's editable lines onto the form, then recalculate each so
     * the tax columns and totals match what the pickers would produce.
     */
    protected function loadLinesFrom(Cheque $cheque): void
    {
        $this->lines = $cheque->lines->map(fn ($l) => [
            'account_id' => $l->account_id,
            ...$this->emptyLineContactState($l->contact_id),
            'description' => $l->description ?? '',
            'amount' => Money::fromCents((int) $l->amount_cents)->toDecimalString(),
            'tax_code_id' => $l->tax_code_id,
            'secondary_tax_code_id' => $l->secondary_tax_code_id,
            'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
            'tax_override' => $l->tax_override_cents !== null ? Money::fromCents((int) $l->tax_override_cents)->toDecimalString() : '',
            'class_id' => $l->class_id,
            'location_id' => $l->location_id,
            'auto_tax_cents' => 0,
            'tax_cents' => (int) $l->tax_cents,
            'secondary_tax_cents' => (int) $l->secondary_tax_cents,
            'total' => (int) $l->amount_cents + (int) $l->tax_cents + (int) $l->secondary_tax_cents,
        ])->all();

        foreach (array_keys($this->lines) as $i) {
            $this->recalcLine($i);
        }
    }

    /**
     * Copy a source cheque's bank, payee, memo and lines into a fresh, unsaved
     * draft. The new cheque keeps today's date and gets its own number from the
     * source's bank — never the source's, which is already spent. A void source
     * is fair game: re-issuing a voided cheque is the usual reason to copy one.
     * Ignores a source from another company (CompanyScope) or one that is gone.
     */
    protected function prefillFrom(int $sourceId): void
    {
        $source = Cheque::query()->with('lines')->find($sourceId);

        if (! $source) {
            return;
        }

        $this->bank_account_id = $source->bank_account_id;
        $this->cheque_no = $this->nextChequeNumber();
        $this->payee_contact_id = $source->payee_contact_id;
        $this->payee_name = $source->payee_name;
        $this->memo = $source->memo ?? '';

        $this->fillPayeeAddress([
            'line1' => $source->payee_line1,
            'line2' => $source->payee_line2,
            'city' => $source->payee_city,
            'region' => $source->payee_region,
            'postal_code' => $source->payee_postal_code,
            'country' => $source->payee_country,
        ]);

        $this->loadLinesFrom($source);

        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function nextChequeNumber(): string
    {
        if (! $this->bank_account_id) {
            return '1001';
        }

        $last = Cheque::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('bank_account_id', $this->bank_account_id)
            ->orderByDesc('id')
            ->first();

        if ($last && ($next = DocumentNumberGenerator::incrementFormat((string) $last->cheque_no)) !== null) {
            return $next;
        }

        return '1001';
    }

    /**
     * Soft warning when this number is already used on the same bank account.
     * Repeats are legitimate — "DD", "EFT" and "e-transfer" stand in for a
     * number on payments that never involved a chequebook, and an operator uses
     * the same label every time — so this never blocks a save; it only catches
     * a genuinely mis-keyed number. Payroll cheques count, because they draw on
     * the same chequebook, and so do voided and deleted ones: the paper is
     * spent either way.
     */
    #[Computed]
    public function duplicateChequeNoWarning(): ?string
    {
        $number = trim($this->cheque_no);

        if ($number === '' || ! $this->bank_account_id) {
            return null;
        }

        $taken = Cheque::query()->withTrashed()
            ->where('bank_account_id', $this->bank_account_id)
            ->where('cheque_no', $number)
            ->when($this->cheque?->exists, fn ($q) => $q->whereKeyNot($this->cheque->id))
            ->exists();

        $taken = $taken || PayrollCheque::query()->withTrashed()
            ->where('bank_account_id', $this->bank_account_id)
            ->where('cheque_no', $number)
            ->exists();

        return $taken
            ? __('Already used on this account. Fine for :label — check it if this is a paper :singular.', [
                'label' => 'DD / EFT / e-transfer',
                'singular' => mb_strtolower($this->company->jurisdiction->cheque('singular')),
            ])
            : null;
    }

    public function updatedBankAccountId(): void
    {
        LastBankAccount::remember($this->company, $this->bank_account_id);

        if (! $this->cheque?->exists) {
            $this->cheque_no = $this->nextChequeNumber();
        }
    }

    /**
     * Default the memo to the supplier's account number so it prints on the
     * cheque (QuickBooks behaviour). The user can still overwrite it.
     *
     * Also seeds any AR/AP line still missing a contact, so picking the payee
     * after coding the line pre-fills it the same way coding the line after
     * picking the payee does.
     */
    protected function afterPayeeSelected(Contact $contact): void
    {
        if ($this->memo === '' && $contact->account_no) {
            $this->memo = $contact->account_no;
        }

        foreach (array_keys($this->lines) as $i) {
            $this->prefillLineContactFromPayee($i);
        }

        // Replace the whole block, never merge: the address belongs to the payee,
        // so switching payee must not leave the previous one's street behind.
        $this->fillPayeeAddress([
            'line1' => $contact->billing_line1,
            'line2' => $contact->billing_line2,
            'city' => $contact->billing_city,
            'region' => $contact->billing_region,
            'postal_code' => $contact->billing_postal_code,
            'country' => $contact->billing_country,
        ]);

        // A freshly seeded address matches the record by definition.
        $this->addressWriteBackAnswered = false;
    }

    /**
     * Clearing the payee clears the address with it — it described that payee.
     */
    public function clearPayee(): void
    {
        $this->clearPayeeCombo();

        $this->fillPayeeAddress([]);
        $this->addressWriteBackAnswered = false;
    }

    /**
     * @param  array<string, ?string>  $address
     */
    protected function fillPayeeAddress(array $address): void
    {
        $this->payee_line1 = (string) ($address['line1'] ?? '');
        $this->payee_line2 = (string) ($address['line2'] ?? '');
        $this->payee_city = (string) ($address['city'] ?? '');
        $this->payee_region = (string) ($address['region'] ?? '');
        $this->payee_postal_code = (string) ($address['postal_code'] ?? '');
        $this->payee_country = (string) ($address['country'] ?? '');
    }

    /**
     * The typed address, in the shape SaveCheque and UpdateContactAddress take.
     *
     * @return array<string, string>
     */
    public function payeeAddressInput(): array
    {
        return [
            'line1' => trim($this->payee_line1),
            'line2' => trim($this->payee_line2),
            'city' => trim($this->payee_city),
            'region' => trim($this->payee_region),
            'postal_code' => trim($this->payee_postal_code),
            'country' => mb_strtoupper(trim($this->payee_country)),
        ];
    }

    /**
     * Seed a line's customer / vendor from the cheque's payee when the payee
     * holds the role the line's account requires — the common case, where the
     * cheque goes to the very customer or vendor whose balance it settles.
     * Leaves the picker blank otherwise (paying a third party on a customer's
     * behalf), and never overwrites a contact already chosen.
     */
    protected function prefillLineContactFromPayee(int $i): void
    {
        $role = $this->lineContactRole($i);

        if ($role === null || ! empty($this->lines[$i]['contact_id']) || ! $this->payee_contact_id) {
            return;
        }

        $holdsRole = Contact::query()
            ->whereKey($this->payee_contact_id)
            ->where(ControlAccountRoles::roleColumn($role), true)
            ->exists();

        if ($holdsRole) {
            $this->lines[$i]['contact_id'] = (int) $this->payee_contact_id;
        }
    }

    /**
     * Whether a line's account forbids sales tax. True for the AR/AP control
     * accounts: the balance the line settles already includes the tax its
     * originating invoice or bill recorded, so taxing it again double-counts it.
     * Named for the tax rule rather than reusing lineContactRole() at the call
     * sites, so the recalc and the markup read as what they mean.
     */
    public function lineExcludesTax(int $i): bool
    {
        return ControlAccountRoles::excludesTax($this->contactRequiringAccounts, $this->lines[$i]['account_id'] ?? null);
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => null, 'description' => '', 'amount' => '0.00',
            ...$this->emptyLineContactState(),
            'tax_code_id' => null, 'secondary_tax_code_id' => null, 'tax_code_ids' => [], 'tax_override' => '', 'class_id' => null, 'location_id' => null,
            'auto_tax_cents' => 0, 'tax_cents' => 0, 'secondary_tax_cents' => 0, 'total' => 0,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $i): void
    {
        if (count($this->lines) <= 1) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null) {
            return;
        }

        $i = (int) explode('.', $key)[0];

        // The tax picker is a multi-select bound to tax_code_ids; fan the
        // (max two) chosen codes back out to the primary/secondary columns.
        if (str_ends_with($key, '.tax_code_ids')) {
            $ids = array_slice(array_values(array_unique(array_filter(
                array_map('intval', (array) $this->lines[$i]['tax_code_ids'])
            ))), 0, 2);
            $this->lines[$i]['tax_code_id'] = $ids[0] ?? null;
            $this->lines[$i]['secondary_tax_code_id'] = $ids[1] ?? null;
        }

        if (str_ends_with($key, '.account_id')) {
            // An AR/AP account needs a customer / vendor and forbids tax; anything
            // else is the reverse. Resolved before either is touched, so a stale
            // pick can't ride along: no customer on an expense line, and no tax
            // code on a receivable.
            if ($this->lineContactRole($i) === null) {
                $this->resetLineContactState($i);

                // Picking an account fills a blank tax code from the account's
                // default — never overwriting one already on the line.
                if ($value && empty($this->lines[$i]['tax_code_id'])) {
                    $this->lines[$i]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
                }
            } else {
                $this->prefillLineContactFromPayee($i);
                $this->resetLineTaxState($i);
            }
        }

        $this->recalcLine($i);
    }

    /**
     * A cheque line carries money when its amount is non-zero; the tax columns
     * are derived from it, so the amount alone decides.
     *
     * @param  array<string, mixed>  $line
     */
    protected function lineHasAmount(array $line): bool
    {
        return (Money::tryFromString((string) ($line['amount'] ?? ''))?->cents ?? 0) !== 0;
    }

    /**
     * Blank a line's whole tax state. Called when a line's account becomes an
     * AR/AP control account, the way resetLineContactState() blanks the picker
     * when it stops being one.
     */
    protected function resetLineTaxState(int $i): void
    {
        $this->lines[$i]['tax_code_id'] = null;
        $this->lines[$i]['secondary_tax_code_id'] = null;
        $this->lines[$i]['tax_code_ids'] = [];
        $this->lines[$i]['tax_override'] = '';
    }

    protected function recalcLine(int $i): void
    {
        $line = $this->lines[$i];

        try {
            $amount = Money::fromString($line['amount'] === '' ? '0' : $line['amount'])->cents;
        } catch (Throwable) {
            $amount = 0;
        }

        // A control-account line never carries tax. Enforced here rather than
        // only on the account change so it also covers hydration: opening a
        // cheque saved before this rule (loadLinesFrom() recalcs every line)
        // shows the corrected figures, and saving writes them.
        if ($this->lineExcludesTax($i)) {
            $this->resetLineTaxState($i);

            $this->lines[$i]['auto_tax_cents'] = 0;
            $this->lines[$i]['tax_cents'] = 0;
            $this->lines[$i]['secondary_tax_cents'] = 0;
            $this->lines[$i]['total'] = $amount;

            return;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $autoTax = $taxCode ? $taxCode->taxFor($amount) : 0;

        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;
        $secondaryTax = $secondaryTaxCode ? $secondaryTaxCode->taxFor($amount) : 0;

        // Keep the multi-select picker in step with the columns when a tax code
        // is set indirectly (account defaults).
        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $override = trim((string) ($line['tax_override'] ?? ''));
        if ($override === '') {
            $taxCents = $autoTax;
        } else {
            $taxCents = Money::tryFromString($override)?->cents ?? $autoTax;
        }

        $this->lines[$i]['auto_tax_cents'] = $autoTax;
        $this->lines[$i]['tax_cents'] = $taxCents;
        $this->lines[$i]['secondary_tax_cents'] = $secondaryTax;
        $this->lines[$i]['total'] = $amount + $taxCents + $secondaryTax;
    }

    public function saveDraft(): void
    {
        if ($this->promptAddressWriteBack('draft')) {
            return;
        }

        if ($this->cheque?->journal_entry_id) {
            $this->addError('lines', __('This :label is already posted. Use Save changes to update it.', [
                'label' => mb_strtolower($this->company->jurisdiction->cheque('singular')),
            ]));

            return;
        }

        // A draft writes nothing to the ledger, so there is nothing yet to
        // mis-attribute: the AR/AP contact is only demanded at the post.
        $this->cheque = app(SaveCheque::class)->handle($this->validatedChequeData(requireContacts: false), $this->cheque);
        $this->storePendingAttachments();

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('cheques.edit', ['company' => $this->company->slug, 'cheque' => $this->cheque->id], navigate: true);
    }

    /**
     * Save and (re)post as ONE unit of work, as the API does: editing a posted
     * cheque rebuilds its existing journal entry in place, so a period, tax or
     * reconciliation lock failure must roll the line rewrite back with it
     * rather than leave the cheque out of step with the ledger.
     */
    public function postCheque(ChequePoster $poster): void
    {
        if ($this->promptAddressWriteBack('post')) {
            return;
        }

        $data = $this->validatedChequeData();
        $wasPosted = $this->cheque?->journal_entry_id !== null;

        try {
            DB::transaction(function () use ($data, $poster, $wasPosted): void {
                $this->cheque = app(SaveCheque::class)->handle($data, $this->cheque);

                $wasPosted ? $poster->repost($this->cheque) : $poster->post($this->cheque);
            });
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        $this->storePendingAttachments();

        $label = $this->company->jurisdiction->cheque('singular');

        Flux::toast(variant: 'success', text: $wasPosted ? $label.' updated.' : $label.' posted.');
        $this->redirectRoute('cheques.show', ['company' => $this->company->slug, 'cheque' => $this->cheque->id], navigate: true);
    }

    /**
     * Park the save and ask whether the payee's record should learn the new
     * address. Returns true when the caller must stand down — the modal is now
     * open and one of its buttons will re-enter the save.
     *
     * Mirrors the duplicate-bill-number interstitial on the bill form.
     */
    protected function promptAddressWriteBack(string $action): bool
    {
        if ($this->addressWriteBackAnswered || ! $this->addressDiffersFromContact()) {
            return false;
        }

        $this->pendingSaveAction = $action;
        Flux::modal('cheque-address-writeback')->show();

        return true;
    }

    /**
     * Whether the typed address is a real change to the linked payee's record.
     * A free-text payee has no record to update, and an address the operator has
     * emptied entirely is a clearing of this cheque, not of the contact.
     */
    protected function addressDiffersFromContact(): bool
    {
        $contact = $this->selectedPayee;
        $address = $this->payeeAddressInput();

        if ($contact === null || AddressLines::isEmpty($address)) {
            return false;
        }

        foreach ([
            'line1' => 'billing_line1',
            'line2' => 'billing_line2',
            'city' => 'billing_city',
            'region' => 'billing_region',
            'postal_code' => 'billing_postal_code',
            'country' => 'billing_country',
        ] as $key => $column) {
            if ($address[$key] !== (string) ($contact->{$column} ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function confirmAddressWriteBack(bool $updateContact, ChequePoster $poster): void
    {
        $this->addressWriteBackAnswered = true;
        Flux::modal('cheque-address-writeback')->close();

        // While someone is editing the payee's record, skip only the write-back
        // (the guard toasts who) — the cheque still saves with its own address.
        if ($updateContact && $contact = $this->selectedPayee) {
            $this->guardEditLockedWrite($contact, fn () => app(UpdateContactAddress::class)->handle($contact, $this->payeeAddressInput()));
            unset($this->selectedPayee);
        }

        $action = $this->pendingSaveAction;
        $this->pendingSaveAction = null;

        $action === 'post' ? $this->postCheque($poster) : $this->saveDraft();
    }

    /**
     * Validate the form and shape it into the cents-based payload
     * {@see SaveCheque} takes. Runs outside the save transaction so a
     * validation failure never opens one.
     *
     * $requireContacts gates the Accounts Receivable / Accounts Payable rule:
     * on at the post, off for a draft save.
     *
     * @return array<string, mixed>
     */
    protected function validatedChequeData(bool $requireContacts = true): array
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::Bank->value)],
            'cheque_no' => ['required', 'string', 'max:40'],
            'cheque_date' => ['required', 'date'],
            'payee_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'payee_name' => ['required', 'string', 'max:255'],
            'payee_line1' => ['nullable', 'string', 'max:255'],
            'payee_line2' => ['nullable', 'string', 'max:255'],
            'payee_city' => ['nullable', 'string', 'max:255'],
            'payee_region' => ['nullable', 'string', 'max:255'],
            'payee_postal_code' => ['nullable', 'string', 'max:255'],
            // max, not size: Livewire submits an untouched field as '' not null.
            'payee_country' => ['nullable', 'string', 'max:2'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'lines.*.amount' => ['required', 'string', new MoneyString],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.tax_override' => ['nullable', 'string', new MoneyString],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            ...AttachmentService::uploadRules(),
        ], [
            // The payee picker has no "use as typed" escape: a new cheque's
            // payee must be picked or quick-added as an Other name.
            'payee_name.required' => __('Choose a payee, or add the name as an Other name.'),
        ]);

        if ($requireContacts) {
            $this->resolveNewLineContacts();
            $this->validateLineContacts();
        }

        return [
            'bank_account_id' => $validated['bank_account_id'],
            'cheque_no' => $validated['cheque_no'],
            'cheque_date' => $validated['cheque_date'],
            'payee_contact_id' => $validated['payee_contact_id'] ?: null,
            'payee_name' => $validated['payee_name'],
            'payee_address' => $this->payeeAddressInput(),
            'memo' => $validated['memo'] ?: null,
            'lines' => array_map(fn ($i, $line) => [
                'account_id' => $line['account_id'],
                // Only an AR/AP line keeps a contact — the account may have been
                // changed after one was picked.
                'contact_id' => $this->lineContactRole($i) !== null
                    ? (($this->lines[$i]['contact_id'] ?? null) ?: null)
                    : null,
                'description' => $line['description'] ?? '',
                'amount_cents' => Money::fromString($line['amount'])->cents,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'tax_override_cents' => ($line['tax_override'] ?? '') !== ''
                    ? Money::fromString($line['tax_override'])->cents
                    : null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ], array_keys($validated['lines']), $validated['lines']),
        ];
    }

    /**
     * Flush files dropped on the form before the cheque existed onto the saved
     * cheque. Deliberately after the save transaction commits — an upload is
     * not something a rolled-back post should have to undo.
     */
    protected function storePendingAttachments(): void
    {
        if ($this->newAttachments === []) {
            return;
        }

        app(AttachmentService::class)->upload($this->cheque, $this->newAttachments, Auth::id());
        $this->newAttachments = [];
        unset($this->attachments);
    }

    /**
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->cheque?->exists ? $this->cheque->attachments()->get() : collect();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->cheque?->exists) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->cheque, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->cheque?->exists) {
            return;
        }

        $service->remove(Attachment::findOrFail($id), $this->cheque);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function bankAccounts()
    {
        // Active bank accounts, plus the one already selected so editing a cheque
        // never drops a since-deactivated account.
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->where('subtype', AccountSubtype::Bank->value)->where('is_active', true));

                if ($this->bank_account_id) {
                    $q->orWhere('id', $this->bank_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        // Accounts already coded on the cheque (e.g. the Accounts Receivable
        // control account on a refund cheque) must always appear, even if they
        // are inactive or otherwise outside the normal expense-coding set.
        $lineAccountIds = collect($this->lines)
            ->pluck('account_id')
            ->filter()
            ->all();

        return Account::query()
            ->where(function ($q) use ($lineAccountIds) {
                $q->where(function ($inner) {
                    $inner->whereIn('type', [AccountType::Expense->value, AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
                        ->where('is_active', true);
                });

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        $lineTaxCodeIds = collect($this->lines)->pluck('tax_code_id')->filter()->all();

        return TaxCode::query()
            ->where(function ($q) use ($lineTaxCodeIds) {
                $q->where(fn ($inner) => $inner->where('is_active', true)->forPurchases());

                if ($lineTaxCodeIds !== []) {
                    $q->orWhereIn('id', $lineTaxCodeIds);
                }
            })
            ->orderBy('code')
            ->get();
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
    public function dimensionColumns(): int
    {
        return (int) $this->tracksClasses + (int) $this->tracksLocations;
    }

    public function totalCents(): int
    {
        return array_sum(array_column($this->lines, 'total'));
    }

    /**
     * Per-tax-code breakdown of the line tax, so the totals can show each tax
     * (e.g. GST and PST) on its own row. Mirrors {@see \App\Support\Tax\LineTaxBreakdown}
     * but reads the unsaved component state.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    #[Computed]
    public function taxBreakdown(): array
    {
        $codes = $this->taxCodeOptions->keyBy('id');
        $rows = [];

        foreach ($this->lines as $line) {
            foreach ([
                [$line['tax_code_id'] ?? null, (int) ($line['tax_cents'] ?? 0)],
                [$line['secondary_tax_code_id'] ?? null, (int) ($line['secondary_tax_cents'] ?? 0)],
            ] as [$id, $cents]) {
                if (! $id || $cents === 0) {
                    continue;
                }

                $code = $codes[$id] ?? null;
                $rows[$id] ??= [
                    'label' => $code ? (string) $code->name : '',
                    'rate' => $code ? $code->ratePercent() : 0.0,
                    'tax_cents' => 0,
                ];
                $rows[$id]['tax_cents'] += $cents;
            }
        }

        return array_values($rows);
    }
}; ?>

<section class="w-full">
    @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else
    <x-edit-lock.status :lock="$this->editLockView" />

    @php($j = $company->jurisdiction)
    <flux:heading size="xl" level="1" class="mb-6">{{ $cheque?->id ? $j->chequeLabel('edit') : $j->chequeLabel('write') }}</flux:heading>

    <form wire:submit="postCheque" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model.live="bank_account_id" :label="__('Bank account')" required data-test="cheque-bank-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:input wire:model.blur="cheque_no" :label="$j->chequeLabel('number')" required data-test="cheque-no-input" />
                @if ($this->duplicateChequeNoWarning)
                    <flux:text class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" data-test="duplicate-cheque-no-warning">
                        <flux:icon name="exclamation-triangle" class="size-4 shrink-0" />
                        {{ $this->duplicateChequeNoWarning }}
                    </flux:text>
                @endif
            </div>

            <flux:input type="date" wire:model="cheque_date" :label="__('Date')" required />

            <div class="md:col-span-2">
                <x-payee-combo
                    :label="__('Pay to the order of')"
                    :options="$this->payeeOptions"
                    :selected-id="$payee_contact_id"
                    :selected-name="$this->selectedPayee?->display_name ?? $payee_name"
                    :selected-roles="$this->selectedPayeeRoles()"
                    :legacy-name="$payee_name"
                    :query="$payee_query"
                    :creating="$payee_creating"
                    :new-name="$new_payee_name"
                    :create-links="$this->payeeCreateLinks"
                    data-test="cheque-payee-combo"
                    required
                />
            </div>

            {{-- Where the cheque gets mailed. Seeded from the payee's record,
                 editable for this cheque, and printed on the cheque itself. --}}
            <div class="space-y-3 rounded-lg border border-border p-4 md:col-span-2" data-test="cheque-payee-address">
                <flux:heading size="sm">{{ __('Address') }}</flux:heading>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="payee_line1" :label="__('Address line 1')" data-test="cheque-payee-line1" />
                    <flux:input wire:model="payee_line2" :label="__('Address line 2')" />
                    <flux:input wire:model="payee_city" :label="__('City')" />
                    <flux:input wire:model="payee_region" :label="__('Province / State')" />
                    <flux:input wire:model="payee_postal_code" :label="__('Postal / ZIP')" />
                    <flux:input wire:model="payee_country" :label="__('Country')" maxlength="2" placeholder="CA" :description="__('Two-letter code')" data-test="cheque-payee-country" />
                </div>
            </div>
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        <div class="overflow-x-auto rounded-lg border border-border">
            <table
                class="w-full text-sm"
                x-on:keydown.tab.capture="tabAddRow($event)"
                x-data="{
                    addRowAndFocus(next) {
                        this.$wire.addLine().then(() => {
                            this.$nextTick(() => this.$root.querySelector('[data-item-input=&quot;' + next + '&quot;], [data-line-first=&quot;' + next + '&quot;]')?.focus());
                        });
                    },
                    tabAddRow(event) {
                        if (event.shiftKey) return;
                        const row = event.target.closest('tr');
                        if (! row || ! row.parentElement) return;
                        const fields = [...row.querySelectorAll('input:not([type=hidden]), select, textarea, [data-test=&quot;line-tax&quot;]')].filter((el) => ! el.disabled && el.offsetParent !== null);
                        if (! fields.length || event.target !== fields[fields.length - 1]) return;
                        const rows = [...row.parentElement.querySelectorAll(':scope > tr')];
                        if (row !== rows[rows.length - 1]) return;
                        event.preventDefault();
                        this.addRowAndFocus(rows.length);
                    },
                }"
            >
                <thead class="hidden bg-muted lg:table-header-group">
                    <tr>
                        <th class="px-2 py-2 text-left w-52">{{ __('Account') }}</th>
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        @if ($this->tracksClasses)
                            <th class="px-2 py-2 text-left w-32">{{ __('Class') }}</th>
                        @endif
                        @if ($this->tracksLocations)
                            <th class="px-2 py-2 text-left w-32">{{ __('Location') }}</th>
                        @endif
                        <th class="px-2 py-2 text-right w-28">{{ __('Total') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}" data-test="cheque-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account" data-line-first="{{ $i }}">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->expenseAccountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                {{-- An Accounts Receivable / Payable line needs its own customer
                                     or vendor: the payee is who the cheque is made out to, which
                                     is often not whose balance it settles. --}}
                                @php($contactRole = $this->contactRequiringAccounts[(int) ($line['account_id'] ?? 0)] ?? null)
                                @if ($contactRole === 'customer')
                                    <x-line-contact-combo
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
                                    <x-line-contact-combo
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
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Amount') }}</span>
                                <x-amount-input model="lines.{{ $i }}.amount" class="lg:text-right" data-test="line-amount" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax') }}</span>
                                {{-- A receivable or payable already includes the tax its invoice or
                                     bill recorded, so the settlement is never taxed again. The cell
                                     says so rather than leaving an empty space to interpret. --}}
                                @if ($contactRole !== null)
                                    <span class="block py-1.5 text-sm text-muted-foreground" data-test="line-tax-excluded">
                                        {{ $contactRole === 'customer' ? __('Included in the invoice') : __('Included in the bill') }}
                                    </span>
                                @else
                                    @php($selectedTaxIds = $line['tax_code_ids'] ?? [])
                                    <flux:dropdown>
                                        <flux:button variant="outline" size="sm" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="line-tax">
                                            <span class="truncate">{{ $this->taxCodeOptions->whereIn('id', $selectedTaxIds)->pluck('code')->implode(', ') ?: __('Select tax') }}</span>
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.checkbox.group wire:model.live="lines.{{ $i }}.tax_code_ids">
                                                @foreach ($this->taxCodeOptions as $opt)
                                                    <flux:menu.checkbox value="{{ $opt->id }}" :disabled="count($selectedTaxIds) === 2 && ! in_array($opt->id, $selectedTaxIds)" keep-open>{{ $opt->code }}</flux:menu.checkbox>
                                                @endforeach
                                            </flux:menu.checkbox.group>
                                        </flux:menu>
                                    </flux:dropdown>
                                    <x-amount-input model="lines.{{ $i }}.tax_override" modifiers=".live.debounce.500ms" size="sm"
                                        class="mt-1 lg:text-right"
                                        placeholder="{{ number_format($line['auto_tax_cents'] / 100, 2) }}"
                                        data-test="line-tax-override" />
                                @endif
                            </td>
                            @if ($this->tracksClasses)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Class') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.class_id" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            @if ($this->tracksLocations)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Location') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.location_id" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="line-total">
                                <span class="text-xs font-medium text-muted-foreground lg:hidden">{{ __('Total') }}</span>
                                <span>{{ number_format($line['total'] / 100, 2) }}</span>
                            </td>
                            <td class="block px-2 pt-2 text-right lg:table-cell lg:p-2">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="hidden bg-muted lg:table-footer-group">
                    @foreach ($this->taxBreakdown as $taxRow)
                        <tr data-test="cheque-tax-row">
                            <td colspan="{{ 4 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="text-base">
                        <td colspan="{{ 4 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="cheque-total">{{ number_format($this->totalCents() / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            {{-- Mobile totals (tfoot is desktop-only) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 text-sm lg:hidden">
                @foreach ($this->taxBreakdown as $taxRow)
                    <div class="flex justify-between"><span class="font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</span><span class="font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</span></div>
                @endforeach
                <div class="flex justify-between text-base"><span class="font-semibold">{{ __('Total') }}</span><span class="font-mono font-semibold">{{ number_format($this->totalCents() / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        {{-- Supporting documents --}}
        <div class="space-y-3 rounded-lg border border-border p-4" data-test="cheque-attachments">
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

            @foreach ($this->attachments as $att)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="cheque-att-{{ $att->id }}" data-test="cheque-attachment-row">
                    <x-attachment-link :attachment="$att" :company="$company" />
                    <flux:button variant="ghost" size="sm" icon="x-mark"
                        wire:click="removeAttachment({{ $att->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        data-test="cheque-attachment-remove" />
                </div>
            @endforeach

            <x-attachment-dropzone model="newAttachments"
                accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                :description="__('PDF, images, or Office docs up to 10 MB each.')"
                data-test="cheque-attachment-input" />

            @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            @if (count($newAttachments) > 0)
                @if ($cheque?->exists)
                    <flux:button variant="filled" wire:click="uploadAttachments" data-test="cheque-attachment-upload">
                        {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
                    </flux:button>
                @else
                    <flux:text class="text-sm text-muted-foreground">{{ __(':count file(s) will be attached when you save.', ['count' => count($newAttachments)]) }}</flux:text>
                @endif
            @endif
        </div>

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                @if ($cheque?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-cheque-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-cheque-button">{{ $j->chequeLabel('post') }}</flux:button>
                @endif
            </div>
        </div>
    </form>

    {{-- The address on a cheque is usually a correction to a stale record, so
         offer to keep the payee's record in step. The cheque stores its own copy
         either way. --}}
    <flux:modal name="cheque-address-writeback" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Address changed') }}</flux:heading>
            <flux:text>
                {{ __('This address is different from the one on :name\'s record. Update their record too, or keep the change on this :label only?', [
                    'name' => $this->selectedPayee?->display_name ?? $payee_name,
                    'label' => mb_strtolower($j->cheque('singular')),
                ]) }}
            </flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="filled" type="button" wire:click="confirmAddressWriteBack(false)" data-test="address-writeback-skip">
                    {{ __('Just this :label', ['label' => mb_strtolower($j->cheque('singular'))]) }}
                </flux:button>
                <flux:button variant="primary" type="button" wire:click="confirmAddressWriteBack(true)" data-test="address-writeback-update">
                    {{ __('Update address') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</section>
