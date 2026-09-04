<?php

use App\Actions\Contacts\MergeContacts;
use App\Actions\Sales\PostCustomerOpeningBalance;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\AttachmentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Title('Customers')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public Company $company;

    private const SORT_FIELDS = ['name', 'open_balance'];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sortField = 'name';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    /** One-shot deep link (?edit={id}) that opens the edit form on load. */
    #[Url(as: 'edit')]
    public ?int $editRequest = null;

    /** One-shot deep link (?new=<name>) from the payee picker: opens the create form prefilled. */
    #[Url(as: 'new')]
    public ?string $newRequest = null;

    public bool $showInactive = false;

    public ?int $editingId = null;

    /** Active tab in the customer form modal. */
    public string $formTab = 'profile';

    public string $f_display_name = '';

    public string $f_company_name = '';

    public string $f_first_name = '';

    public string $f_last_name = '';

    public string $f_job_title = '';

    public string $f_email = '';

    public string $f_phone = '';

    public string $f_mobile = '';

    public string $f_billing_line1 = '';

    public string $f_billing_line2 = '';

    public string $f_billing_city = '';

    public string $f_billing_region = '';

    public string $f_billing_postal_code = '';

    public string $f_billing_country = '';

    public string $f_shipping_line1 = '';

    public string $f_shipping_line2 = '';

    public string $f_shipping_city = '';

    public string $f_shipping_region = '';

    public string $f_shipping_postal_code = '';

    public string $f_shipping_country = '';

    public ?int $f_default_terms_id = null;

    public ?int $f_default_tax_code_id = null;

    public ?int $f_preferred_payment_method_id = null;

    public string $f_credit_limit = '';

    public ?string $f_currency_code = null;

    public ?int $f_parent_id = null;

    public string $f_tax_number = '';

    public string $f_opening_balance = '';

    public string $f_opening_balance_date = '';

    public string $f_notes = '';

    public bool $f_is_active = true;

    /** Outbound email consent — both off unless a user turns them on. */
    public bool $f_invoice_emails_enabled = false;

    public bool $f_reminder_emails_enabled = false;

    public array $newAttachments = [];

    public ?int $mergingId = null;

    public ?int $mergeTargetId = null;

    public bool $mergeConfirmed = false;

    public function mount(Company $company): void
    {
        $this->company = $company;

        // Deep link from the contact statement report. Nulling the request makes
        // Livewire drop ?edit= on first render, so reload/back doesn't reopen the
        // form. Unknown or foreign ids (hidden by CompanyScope) are ignored.
        if ($this->editRequest !== null) {
            if (Contact::whereKey($this->editRequest)->exists()) {
                $this->openEdit($this->editRequest);
            }

            $this->editRequest = null;
        }

        // Deep link from the cheque/expense payee picker ("Create … as a new
        // customer"). openCreate() resets the form, so the prefill comes after
        // it; nulled for the same reason as ?edit= above.
        if ($this->newRequest !== null) {
            $this->openCreate();
            $this->f_display_name = mb_substr(trim($this->newRequest), 0, 255);
            $this->newRequest = null;
        }
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->f_opening_balance_date = $this->company->currentDateTime()->toDateString();
        Flux::modal('customer-form')->show();
    }

    public function openEdit(int $id): void
    {
        $c = Contact::findOrFail($id);

        $this->editingId = $c->id;
        $this->formTab = 'profile';
        $this->f_display_name = $c->display_name;
        $this->f_company_name = $c->company_name ?? '';
        $this->f_first_name = $c->first_name ?? '';
        $this->f_last_name = $c->last_name ?? '';
        $this->f_job_title = $c->job_title ?? '';
        $this->f_email = $c->email ?? '';
        $this->f_phone = $c->phone ?? '';
        $this->f_mobile = $c->mobile ?? '';
        $this->f_billing_line1 = $c->billing_line1 ?? '';
        $this->f_billing_line2 = $c->billing_line2 ?? '';
        $this->f_billing_city = $c->billing_city ?? '';
        $this->f_billing_region = $c->billing_region ?? '';
        $this->f_billing_postal_code = $c->billing_postal_code ?? '';
        $this->f_billing_country = $c->billing_country ?? '';
        $this->f_shipping_line1 = $c->shipping_line1 ?? '';
        $this->f_shipping_line2 = $c->shipping_line2 ?? '';
        $this->f_shipping_city = $c->shipping_city ?? '';
        $this->f_shipping_region = $c->shipping_region ?? '';
        $this->f_shipping_postal_code = $c->shipping_postal_code ?? '';
        $this->f_shipping_country = $c->shipping_country ?? '';
        $this->f_default_terms_id = $c->default_terms_id;
        $this->f_default_tax_code_id = $c->default_tax_code_id;
        $this->f_preferred_payment_method_id = $c->preferred_payment_method_id;
        $this->f_credit_limit = $c->credit_limit_cents !== null ? Money::fromCents((int) $c->credit_limit_cents)->toDecimalString() : '';
        $this->f_currency_code = $c->currency_code;
        $this->f_parent_id = $c->parent_id;
        $this->f_tax_number = $c->tax_number ?? '';
        $this->f_opening_balance = '';
        $this->f_opening_balance_date = '';
        $this->f_notes = $c->notes ?? '';
        $this->f_is_active = $c->is_active;
        $this->f_invoice_emails_enabled = $c->invoice_emails_enabled;
        $this->f_reminder_emails_enabled = $c->reminder_emails_enabled;
        $this->newAttachments = [];

        Flux::modal('customer-form')->show();
    }

    public function copyBillingToShipping(): void
    {
        $this->f_shipping_line1 = $this->f_billing_line1;
        $this->f_shipping_line2 = $this->f_billing_line2;
        $this->f_shipping_city = $this->f_billing_city;
        $this->f_shipping_region = $this->f_billing_region;
        $this->f_shipping_postal_code = $this->f_billing_postal_code;
        $this->f_shipping_country = $this->f_billing_country;
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->editingId) {
            return;
        }

        $customer = Contact::where('is_customer', true)->findOrFail($this->editingId);

        $this->validate(AttachmentService::uploadRules('newAttachments', AttachmentService::CUSTOMER_MAX_KILOBYTES));

        $service->upload($customer, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->editingId) {
            return;
        }

        $customer = Contact::where('is_customer', true)->findOrFail($this->editingId);
        $service->remove(Attachment::findOrFail($id), $customer);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    public function save(): void
    {
        $rules = [
            'f_display_name' => ['required', 'string', 'max:255'],
            'f_company_name' => ['nullable', 'string', 'max:255'],
            'f_first_name' => ['nullable', 'string', 'max:255'],
            'f_last_name' => ['nullable', 'string', 'max:255'],
            'f_job_title' => ['nullable', 'string', 'max:255'],
            'f_email' => ['nullable', 'email', 'max:255'],
            'f_phone' => ['nullable', 'string', 'max:50'],
            'f_mobile' => ['nullable', 'string', 'max:50'],
            'f_billing_line1' => ['nullable', 'string', 'max:255'],
            'f_billing_line2' => ['nullable', 'string', 'max:255'],
            'f_billing_city' => ['nullable', 'string', 'max:255'],
            'f_billing_region' => ['nullable', 'string', 'max:255'],
            'f_billing_postal_code' => ['nullable', 'string', 'max:255'],
            // contacts.*_country are 2-char ISO code columns; max (not size)
            // because Livewire submits an untouched field as '' rather than null.
            'f_billing_country' => ['nullable', 'string', 'max:2'],
            'f_shipping_line1' => ['nullable', 'string', 'max:255'],
            'f_shipping_line2' => ['nullable', 'string', 'max:255'],
            'f_shipping_city' => ['nullable', 'string', 'max:255'],
            'f_shipping_region' => ['nullable', 'string', 'max:255'],
            'f_shipping_postal_code' => ['nullable', 'string', 'max:255'],
            'f_shipping_country' => ['nullable', 'string', 'max:2'],
            'f_default_terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $this->company->id)],
            'f_default_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $this->company->id)],
            'f_preferred_payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $this->company->id)],
            'f_credit_limit' => ['nullable', 'string', new \App\Rules\MoneyString],
            'f_currency_code' => ['nullable', 'string', Rule::in(array_keys(\App\Support\Currency::selectable()))],
            'f_parent_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)->where('is_customer', true)],
            'f_tax_number' => ['nullable', 'string', 'max:50'],
            'f_notes' => ['nullable', 'string'],
            'f_is_active' => ['boolean'],
            'f_invoice_emails_enabled' => ['boolean'],
            'f_reminder_emails_enabled' => ['boolean'],
        ];

        // Opening balance is set once, at creation — like QuickBooks.
        if (! $this->editingId) {
            $rules['f_opening_balance'] = ['nullable', 'string', new \App\Rules\MoneyString];
            $rules['f_opening_balance_date'] = ['nullable', 'required_with:f_opening_balance', 'date'];

            if (count($this->newAttachments) > 0) {
                $rules = array_merge($rules, AttachmentService::uploadRules('newAttachments', AttachmentService::CUSTOMER_MAX_KILOBYTES));
            }
        }

        $validated = $this->validate($rules);

        if ($this->editingId && (int) ($validated['f_parent_id'] ?? 0) === $this->editingId) {
            $this->addError('f_parent_id', __('A customer cannot be its own parent.'));

            return;
        }

        $payload = [
            'parent_id' => $validated['f_parent_id'] ?: null,
            'display_name' => $validated['f_display_name'],
            'company_name' => $validated['f_company_name'] ?: null,
            'first_name' => $validated['f_first_name'] ?: null,
            'last_name' => $validated['f_last_name'] ?: null,
            'job_title' => $validated['f_job_title'] ?: null,
            'email' => $validated['f_email'] ?: null,
            'phone' => $validated['f_phone'] ?: null,
            'mobile' => $validated['f_mobile'] ?: null,
            'billing_line1' => $validated['f_billing_line1'] ?: null,
            'billing_line2' => $validated['f_billing_line2'] ?: null,
            'billing_city' => $validated['f_billing_city'] ?: null,
            'billing_region' => $validated['f_billing_region'] ?: null,
            'billing_postal_code' => $validated['f_billing_postal_code'] ?: null,
            'billing_country' => mb_strtoupper($validated['f_billing_country']) ?: null,
            'shipping_line1' => $validated['f_shipping_line1'] ?: null,
            'shipping_line2' => $validated['f_shipping_line2'] ?: null,
            'shipping_city' => $validated['f_shipping_city'] ?: null,
            'shipping_region' => $validated['f_shipping_region'] ?: null,
            'shipping_postal_code' => $validated['f_shipping_postal_code'] ?: null,
            'shipping_country' => mb_strtoupper($validated['f_shipping_country']) ?: null,
            'default_terms_id' => $validated['f_default_terms_id'] ?: null,
            'default_tax_code_id' => $validated['f_default_tax_code_id'] ?: null,
            'preferred_payment_method_id' => $validated['f_preferred_payment_method_id'] ?: null,
            'credit_limit_cents' => trim($validated['f_credit_limit']) !== '' ? Money::fromString($validated['f_credit_limit'])->cents : null,
            'tax_number' => $validated['f_tax_number'] ?: null,
            'notes' => $validated['f_notes'] ?: null,
            'is_active' => $validated['f_is_active'],
            'invoice_emails_enabled' => $validated['f_invoice_emails_enabled'],
            'reminder_emails_enabled' => $validated['f_reminder_emails_enabled'],
        ];

        $currency = $validated['f_currency_code'] ?: null;
        if ($currency !== null && $this->company->isHomeCurrency($currency)) {
            $currency = null;
        }

        if ($this->editingId) {
            $customer = Contact::findOrFail($this->editingId);
            abort_unless($customer->company_id === $this->company->id, 403);
            // Currency is fixed once the contact has transactions on the books.
            if ($customer->canChangeCurrency()) {
                $payload['currency_code'] = $currency;
            }
            $customer->update($payload);
        } else {
            $openingCents = trim((string) ($validated['f_opening_balance'] ?? '')) !== ''
                ? Money::fromString($validated['f_opening_balance'])->cents
                : 0;
            $asOf = ($validated['f_opening_balance_date'] ?? '') !== ''
                ? $validated['f_opening_balance_date']
                : $this->company->currentDateTime()->toDateString();

            $customer = DB::transaction(function () use ($payload, $currency, $openingCents, $asOf): Contact {
                $customer = Contact::create([
                    ...$payload,
                    'currency_code' => $currency,
                    'is_customer' => true,
                ]);

                if ($openingCents > 0) {
                    app(PostCustomerOpeningBalance::class)->handle($customer, $openingCents, CarbonImmutable::parse($asOf));
                }

                return $customer;
            });

            // Attach buffered files after the contact + opening balance commit,
            // so a rollback never leaves orphaned blobs on disk.
            if (count($this->newAttachments) > 0) {
                app(AttachmentService::class)->upload($customer, $this->newAttachments, Auth::id());
            }
        }

        Flux::modal('customer-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Customer saved.'));
    }

    public function toggleActive(int $id): void
    {
        $customer = Contact::where('is_customer', true)->findOrFail($id);
        abort_unless($customer->company_id === $this->company->id, 403);

        $customer->update(['is_active' => ! $customer->is_active]);

        Flux::toast(variant: 'success', text: $customer->is_active ? __('Customer activated.') : __('Customer deactivated.'));
    }

    public function openMerge(int $id): void
    {
        $customer = Contact::where('is_customer', true)->findOrFail($id);
        abort_unless($customer->company_id === $this->company->id, 403);

        $this->reset(['mergeTargetId', 'mergeConfirmed']);
        $this->resetErrorBag(['mergeTargetId', 'mergeConfirmed']);
        $this->mergingId = $customer->id;
        unset($this->mergeTargets, $this->mergeSummary);

        Flux::modal('customer-merge')->show();
    }

    public function merge(): void
    {
        $this->validate([
            'mergingId' => ['required', 'integer'],
            'mergeTargetId' => ['required', 'integer'],
            'mergeConfirmed' => ['accepted'],
        ], [
            'mergeTargetId.required' => __('Choose the customer to merge into.'),
            'mergeConfirmed.accepted' => __('Please confirm you understand the merge cannot be undone.'),
        ]);

        $loser = Contact::findOrFail($this->mergingId);
        abort_unless($loser->company_id === $this->company->id, 403);
        $survivor = Contact::findOrFail((int) $this->mergeTargetId);

        try {
            app(MergeContacts::class)->handle($loser, $survivor);
        } catch (ValidationException $e) {
            $this->addError('mergeTargetId', collect($e->errors())->flatten()->first());

            return;
        }

        Flux::modal('customer-merge')->close();
        $this->reset(['mergingId', 'mergeTargetId', 'mergeConfirmed']);
        unset($this->customers);

        Flux::toast(variant: 'success', text: __('Customers merged.'));
    }

    /**
     * Eligible surviving customers for the pending merge: active customers in
     * the same currency as the one being merged away, excluding itself.
     *
     * @return \Illuminate\Support\Collection<int, array{value: int, label: string}>
     */
    #[Computed]
    public function mergeTargets()
    {
        $merging = $this->mergingId ? Contact::find($this->mergingId) : null;

        if (! $merging) {
            return collect();
        }

        return Contact::query()
            ->where('is_customer', true)
            ->where('is_active', true)
            ->where('currency_code', $merging->currency_code)
            ->where('id', '!=', $merging->id)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'email'])
            ->map(fn (Contact $c) => [
                'value' => $c->id,
                'label' => $c->display_name.($c->email ? " ({$c->email})" : ''),
            ]);
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

        $invoices = DB::table('invoices')->where('contact_id', $this->mergingId)->whereNull('deleted_at')->count();
        $receipts = DB::table('customer_receipts')->where('contact_id', $this->mergingId)->whereNull('deleted_at')->count();

        return __(':invoices invoice(s) and :receipts payment(s) received will move to the customer you choose.', [
            'invoices' => number_format($invoices),
            'receipts' => number_format($receipts),
        ]);
    }

    /**
     * Soft warning when the entered display name matches another active
     * contact of any role (case-insensitive) — a same-named vendor matters
     * just as much. Informational only; duplicates remain saveable.
     */
    #[Computed]
    public function duplicateNameWarning(): ?string
    {
        $name = mb_strtolower(trim($this->f_display_name));

        if ($name === '') {
            return null;
        }

        $exists = Contact::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(display_name) = ?', [$name])
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        return $exists ? __('Another contact already uses this name.') : null;
    }

    #[Computed]
    public function customers()
    {
        $orderColumn = $this->sortField === 'open_balance' ? 'ar_balance_cents' : 'display_name';
        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        return Contact::query()
            ->with('parent:id,display_name')
            ->where('is_customer', true)
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('display_name', 'like', '%'.$this->search.'%')
                    ->orWhere('company_name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            ->orderBy($orderColumn, $dir)
            ->orderBy('display_name')
            ->paginate(25);
    }

    /**
     * The tabs shown in the customer form, in display order.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            'profile' => __('Profile'),
            'address' => __('Address'),
            'billing' => __('Payment & billing'),
            'tax' => __('Tax info'),
            'notes' => __('Notes'),
            'attachments' => __('Attachments'),
        ];
    }

    #[Computed]
    public function termsOptions()
    {
        return PaymentTerm::query()->where('is_active', true)->orderBy('days')->get(['id', 'name']);
    }

    /**
     * Top-level customers that can be a parent (one level of nesting); never this
     * customer itself.
     */
    #[Computed]
    public function parentCustomerOptions()
    {
        return Contact::query()
            ->where('is_customer', true)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function attachments()
    {
        if (! $this->editingId) {
            return collect();
        }

        return Attachment::query()
            ->where('attachable_type', (new Contact)->getMorphClass())
            ->where('attachable_id', $this->editingId)
            ->latest()
            ->get();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'formTab', 'f_display_name', 'f_company_name', 'f_first_name', 'f_last_name', 'f_job_title',
            'f_email', 'f_phone', 'f_mobile',
            'f_billing_line1', 'f_billing_line2', 'f_billing_city', 'f_billing_region', 'f_billing_postal_code', 'f_billing_country',
            'f_shipping_line1', 'f_shipping_line2', 'f_shipping_city', 'f_shipping_region', 'f_shipping_postal_code', 'f_shipping_country',
            'f_default_terms_id', 'f_default_tax_code_id', 'f_preferred_payment_method_id',
            'f_credit_limit', 'f_currency_code', 'f_parent_id', 'f_tax_number', 'f_opening_balance', 'f_opening_balance_date',
            'f_notes', 'f_invoice_emails_enabled', 'f_reminder_emails_enabled', 'newAttachments',
        ]);
        $this->f_is_active = true;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Customers') }}</flux:heading>
            <flux:subheading>{{ __('People and companies you invoice.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-customer-button">
            {{ __('New customer') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name or email…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->customers as $customer)
            <a href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $customer->id, 'kind' => 'ar']) }}" class="block rounded-lg border border-border p-4 @if(! $customer->is_active) opacity-50 @endif" data-test="customer-card">
                <div class="flex items-end justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $customer->display_name }}</div>
                        @if ($customer->company_name)
                            <div class="text-xs text-muted-foreground">{{ $customer->company_name }}</div>
                        @endif
                        @if ($customer->email)
                            <div class="mt-1 text-sm text-muted-foreground">{{ $customer->email }}</div>
                        @endif
                        @if ($customer->phone)
                            <div class="text-sm text-muted-foreground">{{ $customer->phone }}</div>
                        @endif
                    </div>
                    <div class="text-right font-mono font-semibold">{{ number_format($customer->ar_balance_cents / 100, 2) }}</div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No customers yet.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Name')" /></th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Phone') }}</th>
                    <th class="px-4 py-2 text-right font-medium">
                        <span class="inline-flex items-center justify-end gap-1">
                            <x-sort-header field="open_balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Open balance')" align="right" />
                            <flux:tooltip :content="__('The customer\'s Accounts Receivable balance — it ties to the AR Aging report and their statement. On imported books it can differ when journal entries posted straight to A/R haven\'t been matched to invoices yet (use “Close ledger-settled” on the Open Invoices report).')">
                                <flux:icon name="information-circle" class="size-4 text-muted-foreground" />
                            </flux:tooltip>
                        </span>
                    </th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->customers as $customer)
                    <tr data-test="customer-row" class="@if(! $customer->is_active) opacity-50 @endif">
                        <td class="px-4 py-2">
                            <a
                                href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $customer->id, 'kind' => 'ar']) }}"
                                class="hover:underline"
                                data-test="customer-statement-link"
                            >{{ $customer->display_name }}</a>
                            @if ($customer->company_name)
                                <flux:text class="text-xs text-muted-foreground">{{ $customer->company_name }}</flux:text>
                            @endif
                            @if ($customer->parent)
                                <flux:text class="text-xs text-muted-foreground">{{ __('Sub-customer of :name', ['name' => $customer->parent->display_name]) }}</flux:text>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $customer->email }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $customer->phone }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            <flux:tooltip :content="__('Generate a statement')">
                                <button type="button" wire:click="$dispatch('open-customer-statement', { id: {{ $customer->id }} })" class="hover:underline" data-test="customer-open-balance">{{ number_format($customer->ar_balance_cents / 100, 2) }}</button>
                            </flux:tooltip>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" data-test="customer-actions-button" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil" wire:click="openEdit({{ $customer->id }})" data-test="customer-edit">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="document-text" wire:click="$dispatch('open-customer-statement', { id: {{ $customer->id }} })" data-test="customer-statement-button">
                                        {{ __('Statement…') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="arrows-pointing-in" wire:click="openMerge({{ $customer->id }})" data-test="customer-merge-button">
                                        {{ __('Merge…') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="{{ $customer->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleActive({{ $customer->id }})" data-test="customer-toggle-active">
                                        {{ $customer->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No customers yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->customers->links() }}</div>

    <flux:modal name="customer-form" class="max-w-2xl">
        <form wire:submit="save" class="flex max-h-[80vh] flex-col">
            <flux:heading size="lg" class="mb-4">{{ $editingId ? __('Edit customer') : __('New customer') }}</flux:heading>

            {{-- Tab strip --}}
            <div class="flex flex-wrap gap-1 border-b border-border" role="tablist" data-test="customer-tabs">
                @foreach ($this->tabs as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('formTab', '{{ $key }}')"
                        @class([
                            '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                            'border-accent text-accent' => $formTab === $key,
                            'border-transparent text-muted-foreground hover:text-foreground' => $formTab !== $key,
                        ])
                        data-test="customer-tab-{{ $key }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="space-y-6 overflow-y-auto px-1 py-5">
                {{-- Profile --}}
                @if ($formTab === 'profile')
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <flux:input wire:model.live.debounce.500ms="f_display_name" :label="__('Display name')" required data-test="customer-display-name" />
                            @if ($this->duplicateNameWarning)
                                <flux:text class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" data-test="duplicate-name-warning">
                                    <flux:icon name="exclamation-triangle" class="size-4 shrink-0" />
                                    {{ $this->duplicateNameWarning }}
                                </flux:text>
                            @endif
                        </div>
                        <flux:input wire:model="f_company_name" :label="__('Company name')" data-test="customer-company-name" />
                        <flux:input wire:model="f_first_name" :label="__('First name')" data-test="customer-first-name" />
                        <flux:input wire:model="f_last_name" :label="__('Last name')" data-test="customer-last-name" />
                        <flux:input wire:model="f_job_title" :label="__('Job title')" data-test="customer-job-title" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:input wire:model="f_email" type="email" :label="__('Email')" data-test="customer-email" />
                        <flux:input wire:model="f_phone" :label="__('Work phone')" data-test="customer-phone" />
                        <flux:input wire:model="f_mobile" :label="__('Mobile phone')" data-test="customer-mobile" />
                    </div>

                    <flux:switch wire:model="f_is_active" :label="__('Active')" />
                @endif

                {{-- Address --}}
                @if ($formTab === 'address')
                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <flux:heading size="sm">{{ __('Bill to address') }}</flux:heading>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:input wire:model="f_billing_line1" :label="__('Address line 1')" data-test="customer-billing-line1" />
                            <flux:input wire:model="f_billing_line2" :label="__('Address line 2')" />
                            <flux:input wire:model="f_billing_city" :label="__('City')" />
                            <flux:input wire:model="f_billing_region" :label="__('Province / State')" />
                            <flux:input wire:model="f_billing_postal_code" :label="__('Postal / ZIP')" />
                            <flux:input wire:model="f_billing_country" :label="__('Country')" maxlength="2" placeholder="CA" :description="__('Two-letter code')" data-test="customer-billing-country" />
                        </div>
                    </div>

                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <div class="flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Ship to address') }}</flux:heading>
                            <flux:button type="button" variant="ghost" size="xs" wire:click="copyBillingToShipping" data-test="customer-copy-address">{{ __('Same as billing') }}</flux:button>
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:input wire:model="f_shipping_line1" :label="__('Address line 1')" data-test="customer-shipping-line1" />
                            <flux:input wire:model="f_shipping_line2" :label="__('Address line 2')" />
                            <flux:input wire:model="f_shipping_city" :label="__('City')" />
                            <flux:input wire:model="f_shipping_region" :label="__('Province / State')" />
                            <flux:input wire:model="f_shipping_postal_code" :label="__('Postal / ZIP')" />
                            <flux:input wire:model="f_shipping_country" :label="__('Country')" maxlength="2" placeholder="CA" :description="__('Two-letter code')" data-test="customer-shipping-country" />
                        </div>
                    </div>
                @endif

                {{-- Payment & billing --}}
                @if ($formTab === 'billing')
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:select wire:model="f_default_terms_id" :label="__('Default terms')" :description="__('Auto-populates on new invoices.')" data-test="customer-default-terms">
                            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                            @foreach ($this->termsOptions as $term)
                                <flux:select.option :value="$term->id">{{ $term->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="f_default_tax_code_id" :label="__('Default tax code')">
                            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                            @foreach ($this->taxCodeOptions as $code)
                                <flux:select.option :value="$code->id">{{ $code->code }} — {{ $code->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="f_preferred_payment_method_id" :label="__('Preferred payment method')" data-test="customer-preferred-method">
                            <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                            @foreach ($this->paymentMethodOptions as $method)
                                <flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="f_credit_limit" :label="__('Credit limit')" :placeholder="__('No limit')" :description="__('Warns when a customer\'s open balance would exceed this.')" data-test="customer-credit-limit" />

                        <flux:select wire:model="f_parent_id" :label="__('Sub-customer of')" :description="__('Optionally nest this customer as a job under another customer.')" data-test="customer-parent">
                            <flux:select.option value="">{{ __('— None (top-level) —') }}</flux:select.option>
                            @foreach ($this->parentCustomerOptions as $opt)
                                <flux:select.option :value="$opt->id">{{ $opt->display_name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        @if ($company->isMulticurrencyEnabled())
                            @php($currencyLocked = $editingId && ! \App\Models\Contact::find($editingId)?->canChangeCurrency())
                            <flux:select wire:model="f_currency_code" :label="__('Currency')" :disabled="$currencyLocked">
                                <flux:select.option value="">{{ $company->currency_code }} ({{ __('home') }})</flux:select.option>
                                @foreach ($company->currencies()->where('is_home', false)->where('is_active', true)->orderBy('currency_code')->pluck('currency_code') as $code)
                                    <flux:select.option :value="$code">{{ $code }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @if ($currencyLocked)
                                <flux:text class="text-xs text-muted-foreground">{{ __('Currency is locked once the customer has transactions.') }}</flux:text>
                            @endif
                        @endif
                    </div>

                    <div class="space-y-3 rounded-lg border border-border p-4" data-test="customer-email-preferences">
                        <flux:heading size="sm">{{ __('Email preferences') }}</flux:heading>
                        <flux:text class="text-sm text-muted-foreground">{{ __('LineLedger never emails this customer unless you turn these on. You can always send an invoice by hand from the invoice page.') }}</flux:text>
                        <flux:switch wire:model="f_invoice_emails_enabled" :label="__('Email invoices to this customer')" :description="__('Sends invoices generated by a recurring schedule.')" data-test="customer-invoice-emails" />
                        <flux:switch wire:model="f_reminder_emails_enabled" :label="__('Send payment reminders')" :description="__('Includes the automated overdue notices sent each morning.')" data-test="customer-reminder-emails" />
                    </div>

                    @if (! $editingId)
                        <div class="space-y-3 rounded-lg border border-border p-4" data-test="customer-opening-balance">
                            <flux:heading size="sm">{{ __('Opening balance') }}</flux:heading>
                            <flux:text class="text-sm text-muted-foreground">{{ __('What this customer already owed you when you started using LineLedger. Posts an opening invoice (DR Accounts Receivable / CR Opening Balance Equity) so it shows on their statement and AR Aging.') }}</flux:text>
                            @if (\App\Models\OpeningBalanceState::for($company) !== null && auth()->user()?->ownsCompany($company))
                                <flux:text class="text-sm" data-test="customer-opening-balance-workspace-hint">
                                    {{ __('Tip: the Opening Balances workspace edits these per customer and keeps them tied to your draft trial balance.') }}
                                    <flux:link :href="route('opening-balances.receivables', ['company' => $company->slug])" wire:navigate>{{ __('Open it') }}</flux:link>
                                </flux:text>
                            @endif
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <flux:input wire:model="f_opening_balance" :label="__('Amount owed')" :placeholder="__('0.00')" data-test="customer-opening-balance-amount" />
                                <flux:input wire:model="f_opening_balance_date" type="date" :label="__('As of date')" data-test="customer-opening-balance-date" />
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Tax info --}}
                @if ($formTab === 'tax')
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="f_tax_number" :label="__('Business / Tax number')" :description="__('GST/HST or other tax registration number shown on documents.')" data-test="customer-tax-number" />
                    </div>
                @endif

                {{-- Notes --}}
                @if ($formTab === 'notes')
                    <flux:textarea wire:model="f_notes" :label="__('Notes')" :description="__('Internal only — never shown to the customer.')" rows="5" data-test="customer-notes" />
                @endif

                {{-- Attachments --}}
                @if ($formTab === 'attachments')
                    <div class="space-y-3" data-test="customer-attachments">
                        @if ($editingId)
                            @forelse ($this->attachments as $att)
                                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="customer-attachment-row">
                                    <x-attachment-link :attachment="$att" :company="$company" />
                                    <flux:button variant="ghost" size="sm" icon="x-mark"
                                        wire:click="removeAttachment({{ $att->id }})"
                                        wire:confirm="{{ __('Remove this attachment?') }}"
                                        data-test="customer-attachment-remove" />
                                </div>
                            @empty
                                <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
                            @endforelse
                        @endif

                        <x-attachment-dropzone model="newAttachments"
                            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                            :description="__('PDF, images, or Office docs up to 10 MB each.')"
                            data-test="customer-attachment-input" />

                        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                        @if ($editingId && count($newAttachments) > 0)
                            <flux:button variant="filled" wire:click="uploadAttachments" data-test="customer-attachment-upload">
                                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
                            </flux:button>
                        @elseif (! $editingId && count($newAttachments) > 0)
                            <flux:text class="text-sm text-muted-foreground">{{ __(':count file(s) will be attached when you save.', ['count' => count($newAttachments)]) }}</flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2 border-t border-border pt-4">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="customer-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="customer-merge" class="max-w-lg">
        <form wire:submit="merge" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Merge customer') }}</flux:heading>
                @if ($mergingId && ($mergingCustomer = Contact::find($mergingId)))
                    <flux:subheading>{{ $mergingCustomer->display_name }}</flux:subheading>
                @endif
            </div>

            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    {{ __('Merging cannot be undone. All invoices, payments, and other history move to the customer you choose, and this customer is deactivated and removed from the list.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:select wire:model="mergeTargetId" :label="__('Merge into')" data-test="customer-merge-target">
                <flux:select.option value="">{{ __('— Select customer —') }}</flux:select.option>
                @foreach ($this->mergeTargets as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($mergingId)
                <flux:text class="text-sm text-muted-foreground">{{ $this->mergeSummary }}</flux:text>
            @endif

            <flux:checkbox wire:model="mergeConfirmed" :label="__('I understand this cannot be undone.')" data-test="customer-merge-confirm" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="customer-merge-submit">{{ __('Merge') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <livewire:customer-statement-modal :company="$company" />
</section>
