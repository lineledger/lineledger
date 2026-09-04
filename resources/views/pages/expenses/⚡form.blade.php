<?php

use App\Actions\Purchasing\SaveExpense;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ExpenseStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Concerns\ManagesPayeeCombo;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\AttachmentService;
use App\Services\Posting\ExpensePoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Expense')] class extends Component
{
    use ManagesPayeeCombo;
    use WithFileUploads;

    public Company $company;

    public ?Expense $expense = null;

    public ?int $payment_account_id = null;

    public ?int $payment_method_id = null;

    public string $reference = '';

    public string $expense_date = '';

    public ?int $payee_contact_id = null;

    public string $payee_name = '';

    public string $memo = '';

    /**
     * @var array<int, array{account_id: ?int, description: string, amount: string, tax_code_id: ?int, tax_override: string, class_id: ?int, location_id: ?int, auto_tax_cents: int, tax_cents: int, total: int}>
     */
    public array $lines = [];

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, ?Expense $expense = null): void
    {
        $this->company = $company;

        if ($expense && $expense->exists) {
            abort_if($expense->status === ExpenseStatus::Void, 403);
            abort_if($expense->journal_entry_id, 403, 'Posted expenses cannot be edited. Void and re-create.');

            $this->expense = $expense->load('lines');
            $this->payment_account_id = $expense->payment_account_id;
            $this->payment_method_id = $expense->payment_method_id;
            $this->reference = $expense->reference ?? '';
            $this->expense_date = $expense->expense_date->toDateString();
            $this->payee_contact_id = $expense->payee_contact_id;
            $this->payee_name = $expense->payee_name;
            $this->memo = $expense->memo ?? '';

            $this->lines = $expense->lines->map(fn ($l) => [
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
            $this->expense_date = $this->company->currentDateTime()->toDateString();
            $account = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
            $this->payment_account_id = $account?->id;
            $this->lines = [$this->emptyLine()];
        }
    }

    /**
     * Default the memo to the supplier's account number, mirroring the cheque
     * flow. The user can still overwrite it.
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
        // rather than a single nested field.
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
        // is set indirectly (account/item defaults).
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
        $this->redirectRoute('expenses.edit', ['company' => $this->company->slug, 'expense' => $this->expense->id], navigate: true);
    }

    public function postExpense(ExpensePoster $poster): void
    {
        $this->persist();

        try {
            $poster->post($this->expense);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Expense posted.'));
        $this->redirectRoute('expenses.show', ['company' => $this->company->slug, 'expense' => $this->expense->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'payment_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:40'],
            'expense_date' => ['required', 'date'],
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
            // The payee picker has no "use as typed" escape: a new expense's
            // payee must be picked or quick-added as an Other name.
            'payee_name.required' => __('Choose a payee, or add the name as an Other name.'),
        ]);

        $this->expense = app(SaveExpense::class)->handle([
            'payment_account_id' => $validated['payment_account_id'],
            'payment_method_id' => $validated['payment_method_id'] ?: null,
            'reference' => $validated['reference'] ?: null,
            'expense_date' => $validated['expense_date'],
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
        ], $this->expense);

        if ($this->newAttachments !== []) {
            app(AttachmentService::class)->upload($this->expense, $this->newAttachments, Auth::id());
            $this->newAttachments = [];
            unset($this->attachments);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->expense?->exists ? $this->expense->attachments()->get() : collect();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->expense?->exists) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->expense, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->expense?->exists) {
            return;
        }

        $service->remove(Attachment::findOrFail($id), $this->expense);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function paymentAccounts()
    {
        // Active bank + credit-card accounts, plus the one already selected so
        // editing never drops a since-deactivated account.
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])->where('is_active', true));

                if ($this->payment_account_id) {
                    $q->orWhere('id', $this->payment_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        // Mirror the cheque form: expense/asset/liability/equity accounts, plus
        // any account already on a line so editing never drops one.
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

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
    <flux:heading size="xl" level="1" class="mb-6">{{ $expense?->id ? __('Edit expense') : __('New expense') }}</flux:heading>

    <form wire:submit="postExpense" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model.live="payment_account_id" :label="__('Paid from (bank or credit card)')" required data-test="expense-account-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->paymentAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="payment_method_id" :label="__('Payment method')" data-test="expense-method-select">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->paymentMethodOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="expense_date" :label="__('Date')" required />
            <flux:input wire:model="reference" :label="__('Reference no.')" :description="__('Confirmation or cheque number (optional).')" data-test="expense-reference-input" />

            <div class="md:col-span-2">
                <x-payee-combo
                    :label="__('Paid to')"
                    :options="$this->payeeOptions"
                    :selected-id="$payee_contact_id"
                    :selected-name="$this->selectedPayee?->display_name ?? $payee_name"
                    :selected-roles="$this->selectedPayeeRoles()"
                    :legacy-name="$payee_name"
                    :query="$payee_query"
                    :creating="$payee_creating"
                    :new-name="$new_payee_name"
                    :create-links="$this->payeeCreateLinks"
                    data-test="expense-payee-combo"
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
                        <tr wire:key="line-{{ $i }}" data-test="expense-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
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
                        <tr data-test="expense-tax-row">
                            <td colspan="{{ 4 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="text-base">
                        <td colspan="{{ 4 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="expense-total">{{ number_format($this->totalCents() / 100, 2) }}</td>
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
        <div class="space-y-3 rounded-lg border border-border p-4" data-test="expense-attachments">
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

            @foreach ($this->attachments as $att)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="expense-att-{{ $att->id }}" data-test="expense-attachment-row">
                    <x-attachment-link :attachment="$att" :company="$company" />
                    <flux:button variant="ghost" size="sm" icon="x-mark"
                        wire:click="removeAttachment({{ $att->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        data-test="expense-attachment-remove" />
                </div>
            @endforeach

            <x-attachment-dropzone model="newAttachments"
                accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                :description="__('PDF, images, or Office docs up to 10 MB each.')"
                data-test="expense-attachment-input" />

            @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            @if (count($newAttachments) > 0)
                @if ($expense?->exists)
                    <flux:button variant="filled" wire:click="uploadAttachments" data-test="expense-attachment-upload">
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
                <flux:button variant="primary" type="submit" data-test="post-expense-button">{{ __('Post expense') }}</flux:button>
            </div>
        </div>
    </form>
</section>
