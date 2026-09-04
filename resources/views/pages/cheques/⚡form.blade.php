<?php

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Concerns\ManagesPayeeCombo;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Cheque;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Location;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\AttachmentService;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Cheque')] class extends Component
{
    use ManagesPayeeCombo;
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
     * @var array<int, array{account_id: ?int, description: string, amount: string, tax_code_id: ?int, tax_override: string, class_id: ?int, location_id: ?int, auto_tax_cents: int, tax_cents: int, total: int}>
     */
    public array $lines = [];

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, ?Cheque $cheque = null): void
    {
        $this->company = $company;

        if ($cheque && $cheque->exists) {
            abort_if($cheque->status === ChequeStatus::Void, 403);
            abort_if($cheque->journal_entry_id, 403, 'Posted cheques cannot be edited. Void and re-create.');

            $this->cheque = $cheque->load('lines');
            $this->bank_account_id = $cheque->bank_account_id;
            $this->cheque_no = $cheque->cheque_no;
            $this->cheque_date = $cheque->cheque_date->toDateString();
            $this->payee_contact_id = $cheque->payee_contact_id;
            $this->payee_name = $cheque->payee_name;
            $this->memo = $cheque->memo ?? '';

            $this->lines = $cheque->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
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
        } else {
            $this->cheque_date = $this->company->currentDateTime()->toDateString();
            $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
            $this->bank_account_id = $bank?->id;
            $this->cheque_no = $this->nextChequeNumber();
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

    public function updatedBankAccountId(): void
    {
        if (! $this->cheque?->exists) {
            $this->cheque_no = $this->nextChequeNumber();
        }
    }

    /**
     * Default the memo to the supplier's account number so it prints on the
     * cheque (QuickBooks behaviour). The user can still overwrite it.
     */
    protected function afterPayeeSelected(Contact $contact): void
    {
        if ($this->memo === '' && $contact->account_no) {
            $this->memo = $contact->account_no;
        }
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => null, 'description' => '', 'amount' => '0.00',
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

        // Picking an account fills a blank tax code from the account's
        // default — never overwriting one already on the line.
        if (str_ends_with($key, '.account_id') && $value && empty($this->lines[$i]['tax_code_id'])) {
            $this->lines[$i]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
        }

        $this->recalcLine($i);
    }

    protected function recalcLine(int $i): void
    {
        $line = $this->lines[$i];

        try {
            $amount = Money::fromString($line['amount'] === '' ? '0' : $line['amount'])->cents;
        } catch (Throwable) {
            $amount = 0;
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
        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('cheques.edit', ['company' => $this->company->slug, 'cheque' => $this->cheque->id], navigate: true);
    }

    public function postCheque(ChequePoster $poster): void
    {
        $this->persist();

        try {
            $poster->post($this->cheque);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $this->company->jurisdiction->cheque('singular').' posted.');
        $this->redirectRoute('cheques.show', ['company' => $this->company->slug, 'cheque' => $this->cheque->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::Bank->value)],
            'cheque_no' => ['required', 'string', 'max:40'],
            'cheque_date' => ['required', 'date'],
            'payee_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'payee_name' => ['required', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
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

        $this->cheque = app(SaveCheque::class)->handle([
            'bank_account_id' => $validated['bank_account_id'],
            'cheque_no' => $validated['cheque_no'],
            'cheque_date' => $validated['cheque_date'],
            'payee_contact_id' => $validated['payee_contact_id'] ?: null,
            'payee_name' => $validated['payee_name'],
            'memo' => $validated['memo'] ?: null,
            'lines' => array_map(fn ($line) => [
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'amount_cents' => Money::fromString($line['amount'])->cents,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'tax_override_cents' => ($line['tax_override'] ?? '') !== ''
                    ? Money::fromString($line['tax_override'])->cents
                    : null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ], $validated['lines']),
        ], $this->cheque);

        if ($this->newAttachments !== []) {
            app(AttachmentService::class)->upload($this->cheque, $this->newAttachments, Auth::id());
            $this->newAttachments = [];
            unset($this->attachments);
        }
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

            <flux:input wire:model="cheque_no" :label="$j->chequeLabel('number')" required data-test="cheque-no-input" />
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
                <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="post-cheque-button">{{ $j->chequeLabel('post') }}</flux:button>
            </div>
        </div>
    </form>
</section>
