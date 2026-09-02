<?php

use App\Actions\Contacts\MergeContacts;
use App\Enums\AccountType;
use App\Enums\JurisdictionCapability;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Services\AttachmentService;
use App\Support\Currency;
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

new #[Title('Vendors')] class extends Component
{
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

    public bool $showInactive = false;

    public ?int $editingId = null;

    public string $f_display_name = '';

    public string $f_company_name = '';

    public string $f_email = '';

    public string $f_phone = '';

    public string $f_account_no = '';

    public string $f_first_name = '';

    public string $f_last_name = '';

    public string $f_job_title = '';

    public string $f_mobile = '';

    public string $f_billing_line1 = '';

    public string $f_billing_line2 = '';

    public string $f_billing_city = '';

    public string $f_billing_region = '';

    public string $f_billing_postal_code = '';

    public string $f_billing_country = '';

    public ?string $f_currency_code = null;

    public ?int $f_default_expense_account_id = null;

    public ?int $f_default_terms_id = null;

    public ?int $f_default_tax_code_id = null;

    public string $f_notes = '';

    public bool $f_is_active = true;

    public bool $f_track_1099 = false;

    public bool $f_track_t4a = false;

    public string $f_tax_number = '';

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
        Flux::modal('vendor-form')->show();
    }

    public function openEdit(int $id): void
    {
        $c = Contact::findOrFail($id);

        $this->editingId = $c->id;
        $this->f_display_name = $c->display_name;
        $this->f_company_name = $c->company_name ?? '';
        $this->f_email = $c->email ?? '';
        $this->f_phone = $c->phone ?? '';
        $this->f_account_no = $c->account_no ?? '';
        $this->f_first_name = $c->first_name ?? '';
        $this->f_last_name = $c->last_name ?? '';
        $this->f_job_title = $c->job_title ?? '';
        $this->f_mobile = $c->mobile ?? '';
        $this->f_billing_line1 = $c->billing_line1 ?? '';
        $this->f_billing_line2 = $c->billing_line2 ?? '';
        $this->f_billing_city = $c->billing_city ?? '';
        $this->f_billing_region = $c->billing_region ?? '';
        $this->f_billing_postal_code = $c->billing_postal_code ?? '';
        $this->f_billing_country = $c->billing_country ?? '';
        $this->f_currency_code = $c->currency_code;
        $this->f_default_expense_account_id = $c->default_expense_account_id;
        $this->f_default_terms_id = $c->default_terms_id;
        $this->f_default_tax_code_id = $c->default_tax_code_id;
        $this->f_notes = $c->notes ?? '';
        $this->f_is_active = $c->is_active;
        $this->f_track_1099 = (bool) $c->track_1099;
        $this->f_track_t4a = (bool) $c->track_t4a;
        $this->f_tax_number = $c->tax_number ?? '';
        $this->newAttachments = [];

        Flux::modal('vendor-form')->show();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->editingId) {
            return;
        }

        $vendor = Contact::where('is_vendor', true)->findOrFail($this->editingId);

        $this->validate(AttachmentService::uploadRules());

        $service->upload($vendor, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->editingId) {
            return;
        }

        $vendor = Contact::where('is_vendor', true)->findOrFail($this->editingId);
        $service->remove(Attachment::findOrFail($id), $vendor);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_display_name' => ['required', 'string', 'max:255'],
            'f_company_name' => ['nullable', 'string', 'max:255'],
            'f_email' => ['nullable', 'email', 'max:255'],
            'f_phone' => ['nullable', 'string', 'max:50'],
            'f_account_no' => ['nullable', 'string', 'max:100'],
            'f_first_name' => ['nullable', 'string', 'max:255'],
            'f_last_name' => ['nullable', 'string', 'max:255'],
            'f_job_title' => ['nullable', 'string', 'max:255'],
            'f_mobile' => ['nullable', 'string', 'max:50'],
            'f_billing_line1' => ['nullable', 'string', 'max:255'],
            'f_billing_line2' => ['nullable', 'string', 'max:255'],
            'f_billing_city' => ['nullable', 'string', 'max:255'],
            'f_billing_region' => ['nullable', 'string', 'max:255'],
            'f_billing_postal_code' => ['nullable', 'string', 'max:255'],
            // contacts.billing_country is a 2-char ISO code column; max (not size)
            // because Livewire submits an untouched field as '' rather than null.
            'f_billing_country' => ['nullable', 'string', 'max:2'],
            'f_currency_code' => ['nullable', 'string', Rule::in(array_keys(Currency::selectable()))],
            'f_default_expense_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'f_default_terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $this->company->id)],
            'f_default_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $this->company->id)],
            'f_notes' => ['nullable', 'string'],
            'f_is_active' => ['boolean'],
            'f_track_1099' => ['boolean'],
            'f_track_t4a' => ['boolean'],
            'f_tax_number' => ['nullable', 'string', 'max:50'],
        ]);

        $payload = [
            'display_name' => $validated['f_display_name'],
            'company_name' => $validated['f_company_name'] ?: null,
            'email' => $validated['f_email'] ?: null,
            'phone' => $validated['f_phone'] ?: null,
            'account_no' => $validated['f_account_no'] ?: null,
            'first_name' => $validated['f_first_name'] ?: null,
            'last_name' => $validated['f_last_name'] ?: null,
            'job_title' => $validated['f_job_title'] ?: null,
            'mobile' => $validated['f_mobile'] ?: null,
            'billing_line1' => $validated['f_billing_line1'] ?: null,
            'billing_line2' => $validated['f_billing_line2'] ?: null,
            'billing_city' => $validated['f_billing_city'] ?: null,
            'billing_region' => $validated['f_billing_region'] ?: null,
            'billing_postal_code' => $validated['f_billing_postal_code'] ?: null,
            'billing_country' => mb_strtoupper($validated['f_billing_country']) ?: null,
            'default_expense_account_id' => $validated['f_default_expense_account_id'] ?: null,
            'default_terms_id' => $validated['f_default_terms_id'] ?: null,
            'default_tax_code_id' => $validated['f_default_tax_code_id'] ?: null,
            'notes' => $validated['f_notes'] ?: null,
            'is_active' => $validated['f_is_active'],
            // 1099 tracking is a US-only concept; never set it for other jurisdictions.
            'track_1099' => $this->company->supports(JurisdictionCapability::Vendor1099Tracking) && $validated['f_track_1099'],
            // T4A tracking is the Canadian analog.
            'track_t4a' => $this->company->supports(JurisdictionCapability::VendorT4ATracking) && $validated['f_track_t4a'],
            'tax_number' => $validated['f_tax_number'] ?: null,
        ];

        $currency = $validated['f_currency_code'] ?: null;
        if ($currency !== null && $this->company->isHomeCurrency($currency)) {
            $currency = null;
        }

        if ($this->editingId) {
            $contact = Contact::findOrFail($this->editingId);
            abort_unless($contact->company_id === $this->company->id, 403);
            // Currency is fixed once the contact has transactions on the books.
            if ($contact->canChangeCurrency()) {
                $payload['currency_code'] = $currency;
            }
            $contact->update([...$payload, 'is_vendor' => true]);
        } else {
            Contact::create([...$payload, 'currency_code' => $currency, 'is_vendor' => true]);
        }

        Flux::modal('vendor-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Vendor saved.'));
    }

    public function toggleActive(int $id): void
    {
        $vendor = Contact::where('is_vendor', true)->findOrFail($id);
        abort_unless($vendor->company_id === $this->company->id, 403);

        $vendor->update(['is_active' => ! $vendor->is_active]);

        Flux::toast(variant: 'success', text: $vendor->is_active ? __('Vendor activated.') : __('Vendor deactivated.'));
    }

    public function openMerge(int $id): void
    {
        $vendor = Contact::where('is_vendor', true)->findOrFail($id);
        abort_unless($vendor->company_id === $this->company->id, 403);

        $this->reset(['mergeTargetId', 'mergeConfirmed']);
        $this->resetErrorBag(['mergeTargetId', 'mergeConfirmed']);
        $this->mergingId = $vendor->id;
        unset($this->mergeTargets, $this->mergeSummary);

        Flux::modal('vendor-merge')->show();
    }

    public function merge(): void
    {
        $this->validate([
            'mergingId' => ['required', 'integer'],
            'mergeTargetId' => ['required', 'integer'],
            'mergeConfirmed' => ['accepted'],
        ], [
            'mergeTargetId.required' => __('Choose the vendor to merge into.'),
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

        Flux::modal('vendor-merge')->close();
        $this->reset(['mergingId', 'mergeTargetId', 'mergeConfirmed']);
        unset($this->vendors);

        Flux::toast(variant: 'success', text: __('Vendors merged.'));
    }

    /**
     * Eligible surviving vendors for the pending merge: active vendors in the
     * same currency as the one being merged away, excluding itself.
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
            ->where('is_vendor', true)
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

        $bills = DB::table('bills')->where('contact_id', $this->mergingId)->whereNull('deleted_at')->count();
        $payments = DB::table('bill_payments')->where('contact_id', $this->mergingId)->whereNull('deleted_at')->count();

        return __(':bills bill(s) and :payments payment(s) will move to the vendor you choose.', [
            'bills' => number_format($bills),
            'payments' => number_format($payments),
        ]);
    }

    /**
     * Soft warning when the entered display name matches another active
     * contact of any role (case-insensitive) — a same-named customer matters
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
    public function vendors()
    {
        $orderColumn = $this->sortField === 'open_balance' ? 'ap_balance_cents' : 'display_name';
        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        return Contact::query()
            ->where('is_vendor', true)
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

    #[Computed]
    public function termsOptions()
    {
        return PaymentTerm::query()->where('is_active', true)->orderBy('days')->get(['id', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        return Account::query()
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
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
            'editingId', 'f_display_name', 'f_company_name', 'f_email', 'f_phone',
            'f_account_no', 'f_first_name', 'f_last_name', 'f_job_title', 'f_mobile',
            'f_billing_line1', 'f_billing_line2', 'f_billing_city', 'f_billing_region', 'f_billing_postal_code', 'f_billing_country',
            'f_currency_code', 'f_default_expense_account_id',
            'f_default_terms_id', 'f_default_tax_code_id', 'f_notes', 'newAttachments',
            'f_track_1099', 'f_track_t4a', 'f_tax_number',
        ]);
        $this->f_is_active = true;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Vendors') }}</flux:heading>
            <flux:subheading>{{ __('Suppliers you receive bills from.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-vendor-button">
            {{ __('New vendor') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name or email…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->vendors as $vendor)
            <a href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $vendor->id, 'kind' => 'ap']) }}" class="block rounded-lg border border-border p-4 @if(! $vendor->is_active) opacity-50 @endif" data-test="vendor-card">
                <div class="flex items-end justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $vendor->display_name }}</div>
                        @if ($vendor->company_name)
                            <div class="text-xs text-muted-foreground">{{ $vendor->company_name }}</div>
                        @endif
                        @if ($vendor->email)
                            <div class="mt-1 text-sm text-muted-foreground">{{ $vendor->email }}</div>
                        @endif
                        @if ($vendor->phone)
                            <div class="text-sm text-muted-foreground">{{ $vendor->phone }}</div>
                        @endif
                    </div>
                    <div class="text-right font-mono font-semibold">{{ number_format($vendor->ap_balance_cents / 100, 2) }}</div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No vendors yet.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left"><x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Name')" /></th>
                    <th class="px-4 py-2 text-left">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Phone') }}</th>
                    <th class="px-4 py-2 text-right">
                        <span class="inline-flex items-center justify-end gap-1">
                            <x-sort-header field="open_balance" :current-field="$sortField" :current-dir="$sortDir" :label="__('Open balance')" align="right" />
                            <flux:tooltip :content="__('The vendor\'s Accounts Payable balance — it ties to the AP Aging report and their statement. On imported books it can differ when journal entries posted straight to A/P haven\'t been matched to bills yet (use “Close ledger-settled” on the Open Bills report).')">
                                <flux:icon name="information-circle" class="size-4 text-muted-foreground" />
                            </flux:tooltip>
                        </span>
                    </th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->vendors as $vendor)
                    <tr data-test="vendor-row" class="@if(! $vendor->is_active) opacity-50 @endif">
                        <td class="px-4 py-2">
                            <a
                                href="{{ route('reports.contact-statement', ['company' => $company->slug, 'contact' => $vendor->id, 'kind' => 'ap']) }}"
                                class="hover:underline"
                                data-test="vendor-statement-link"
                            >{{ $vendor->display_name }}</a>
                            @if ($vendor->company_name)
                                <flux:text class="text-xs text-muted-foreground">{{ $vendor->company_name }}</flux:text>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $vendor->email }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $vendor->phone }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($vendor->ap_balance_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" data-test="vendor-actions-button" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil" wire:click="openEdit({{ $vendor->id }})" data-test="vendor-edit">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="arrows-pointing-in" wire:click="openMerge({{ $vendor->id }})" data-test="vendor-merge-button">
                                        {{ __('Merge…') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="{{ $vendor->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleActive({{ $vendor->id }})" data-test="vendor-toggle-active">
                                        {{ $vendor->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No vendors yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->vendors->links() }}</div>

    <flux:modal name="vendor-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit vendor') : __('New vendor') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <flux:input wire:model.live.debounce.500ms="f_display_name" :label="__('Display name')" required data-test="vendor-display-name" />
                    @if ($this->duplicateNameWarning)
                        <flux:text class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" data-test="duplicate-name-warning">
                            <flux:icon name="exclamation-triangle" class="size-4 shrink-0" />
                            {{ $this->duplicateNameWarning }}
                        </flux:text>
                    @endif
                </div>
                <flux:input wire:model="f_company_name" :label="__('Company name')" />
                <flux:input wire:model="f_account_no" :label="__('Account no.')" :description="__('Your account number with this supplier; prints on payment cheque memos.')" data-test="vendor-account-no" />
                <flux:input wire:model="f_email" type="email" :label="__('Email')" />
                <flux:input wire:model="f_phone" :label="__('Phone')" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="f_default_terms_id" :label="__('Default terms')">
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
            </div>

            <div class="space-y-4 border-t border-border pt-4">
                <flux:heading size="sm">{{ __('More details') }}</flux:heading>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_first_name" :label="__('First name')" />
                    <flux:input wire:model="f_last_name" :label="__('Last name')" />
                    <flux:input wire:model="f_job_title" :label="__('Job title')" />
                    <flux:input wire:model="f_mobile" :label="__('Mobile')" />
                </div>

                <flux:select wire:model="f_default_expense_account_id" :label="__('Default expense account')" :description="__('Pre-fills the account on new bills for this vendor.')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->expenseAccountOptions as $acct)
                        <flux:select.option :value="$acct->id">{{ $acct->code }} — {{ $acct->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @php($currencyLocked = $editingId && ! \App\Models\Contact::find($editingId)?->canChangeCurrency())
                <flux:select wire:model="f_currency_code" :label="__('Currency')" :disabled="$currencyLocked" data-test="vendor-currency">
                    <flux:select.option value="">{{ $company->currency_code }} ({{ __('home') }})</flux:select.option>
                    @foreach ($company->currencies()->where('is_home', false)->where('is_active', true)->orderBy('currency_code')->pluck('currency_code') as $code)
                        <flux:select.option :value="$code">{{ $code }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($currencyLocked)
                    <flux:text class="text-xs text-muted-foreground">{{ __('Currency is locked once a vendor has posted transactions.') }}</flux:text>
                @endif

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="f_billing_line1" :label="__('Address line 1')" />
                    <flux:input wire:model="f_billing_line2" :label="__('Address line 2')" />
                    <flux:input wire:model="f_billing_city" :label="__('City')" />
                    <flux:input wire:model="f_billing_region" :label="__('Province / State')" />
                    <flux:input wire:model="f_billing_postal_code" :label="__('Postal code')" />
                    <flux:input wire:model="f_billing_country" :label="__('Country')" maxlength="2" placeholder="CA" :description="__('Two-letter code')" data-test="vendor-billing-country" />
                </div>
            </div>

            <flux:textarea wire:model="f_notes" :label="__('Notes')" rows="2" />

            @if ($company->supports(\App\Enums\JurisdictionCapability::Vendor1099Tracking))
                <div class="space-y-4 border-t border-border pt-4">
                    <flux:switch wire:model.live="f_track_1099" :label="__('Track for 1099-NEC')" :description="__('Include this vendor on the year-end 1099 Summary report.')" data-test="vendor-track-1099" />
                    @if ($f_track_1099)
                        <flux:input wire:model="f_tax_number" :label="__('Tax ID (EIN or SSN)')" maxlength="50" data-test="vendor-tax-id" />
                    @endif
                </div>
            @elseif ($company->supports(\App\Enums\JurisdictionCapability::VendorT4ATracking))
                <div class="space-y-4 border-t border-border pt-4">
                    <flux:switch wire:model.live="f_track_t4a" :label="__('Track for T4A')" :description="__('Include this contractor on the year-end T4A report (Box 048, fees for services).')" data-test="vendor-track-t4a" />
                    @if ($f_track_t4a)
                        <flux:input wire:model="f_tax_number" :label="__('Business number / SIN')" maxlength="50" data-test="vendor-t4a-tax-id" />
                    @endif
                </div>
            @endif

            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            @if ($editingId)
                <div class="space-y-3 border-t border-border pt-4" data-test="vendor-attachments">
                    <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

                    @forelse ($this->attachments as $att)
                        <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="vendor-attachment-row">
                            <x-attachment-link :attachment="$att" :company="$company" />
                            <flux:button variant="ghost" size="sm" icon="x-mark"
                                wire:click="removeAttachment({{ $att->id }})"
                                wire:confirm="{{ __('Remove this attachment?') }}"
                                data-test="vendor-attachment-remove" />
                        </div>
                    @empty
                        <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
                    @endforelse

                    <x-attachment-dropzone model="newAttachments"
                        accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                        :description="__('PDF, images, or Office docs up to 10 MB each.')"
                        data-test="vendor-attachment-input" />

                    @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

                    @if (count($newAttachments) > 0)
                        <flux:button variant="filled" wire:click="uploadAttachments" data-test="vendor-attachment-upload">
                            {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
                        </flux:button>
                    @endif
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="vendor-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="vendor-merge" class="max-w-lg">
        <form wire:submit="merge" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Merge vendor') }}</flux:heading>
                @if ($mergingId && ($mergingVendor = Contact::find($mergingId)))
                    <flux:subheading>{{ $mergingVendor->display_name }}</flux:subheading>
                @endif
            </div>

            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    {{ __('Merging cannot be undone. All bills, payments, and other history move to the vendor you choose, and this vendor is deactivated and removed from the list.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:select wire:model="mergeTargetId" :label="__('Merge into')" data-test="vendor-merge-target">
                <flux:select.option value="">{{ __('— Select vendor —') }}</flux:select.option>
                @foreach ($this->mergeTargets as $option)
                    <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($mergingId)
                <flux:text class="text-sm text-muted-foreground">{{ $this->mergeSummary }}</flux:text>
            @endif

            <flux:checkbox wire:model="mergeConfirmed" :label="__('I understand this cannot be undone.')" data-test="vendor-merge-confirm" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="vendor-merge-submit">{{ __('Merge') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
