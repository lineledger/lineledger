<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Inventory\EnsureInventoryAccounts;
use App\Actions\MasterData\EnsureDefaultFund;
use App\Actions\Payroll\EnsurePayrollAccounts;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\Country;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\Section;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\User;
use App\Rules\CompanyName;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;
use App\Support\CompanyPermissions;
use App\Support\Storage\StorageDisks;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Company $companyModel;

    public string $companyName = '';

    public string $lockDate = '';

    public string $lockPassword = '';

    public string $currencyCode = 'CAD';

    public int $fiscalYearStartMonth = 1;

    public string $timezone = 'UTC';

    public bool $autoApplyCustomerCredits = false;

    public bool $insightsAiNarration = false;

    public bool $warnDuplicateBillNo = true;

    public string $chequeOffsetX = '';

    public string $chequeOffsetY = '';

    public bool $requireTwoFactor = false;

    public bool $featuresEmployees = true;

    public bool $featuresPayroll = false;

    public float $qhsfRatePct = 0.0;

    public float $cnesstRatePct = 0.0;

    public bool $wsdrfApplicable = false;

    public bool $featuresInventory = true;

    public bool $featuresFixedAssets = true;

    public bool $featuresEstimates = true;

    public bool $featuresSalesOrders = true;

    public bool $featuresPurchaseOrders = true;

    public bool $featuresRecurringInvoices = true;

    public bool $featuresRecurringBills = true;

    public bool $featuresClasses = false;

    public bool $featuresLocations = false;

    public bool $featuresBudgets = true;

    public bool $featuresMembership = false;

    public bool $featuresFundraising = false;

    public string $organizationType = '';

    public string $legalStructure = '';

    public string $charityRegistrationNumber = '';

    public string $contributionMethod = '';

    public bool $featuresFunds = false;

    public string $region = '';

    public string $addressLine1 = '';

    public string $addressCity = '';

    public string $phone = '';

    public string $website = '';

    public string $email = '';

    public string $brandName = '';

    public string $brandInitials = '';

    public string $brandTextColor = '#ffffff';

    public string $brandBackgroundColor = '#18181b';

    public $logo = null;

    public bool $removeLogo = false;

    public $documentLogo = null;

    public bool $removeDocumentLogo = false;

    public int $documentLogoMaxHeight = 64;

    public array $companyData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentCompany = false;

    public ?int $editingMemberId = null;

    public string $editMemberName = '';

    public string $editRole = '';

    /** @var array<int, string> */
    public array $editSections = [];

    public function mount(Company $company): void
    {
        $this->companyModel = $company;
        $this->companyName = $company->name;
        $this->lockDate = $company->lock_date?->toDateString() ?? '';
        $this->currencyCode = $company->currency_code;
        $this->fiscalYearStartMonth = $company->fiscal_year_start_month;
        $this->timezone = $company->timezone ?: 'UTC';
        $this->autoApplyCustomerCredits = (bool) $company->auto_apply_customer_credits;
        $this->insightsAiNarration = $company->insightsAiNarrationEnabled();
        $this->warnDuplicateBillNo = (bool) $company->warn_duplicate_bill_no;
        $this->chequeOffsetX = $company->cheque_offset_x !== null ? (string) $company->cheque_offset_x : '';
        $this->chequeOffsetY = $company->cheque_offset_y !== null ? (string) $company->cheque_offset_y : '';
        $this->requireTwoFactor = (bool) $company->require_two_factor;
        $this->featuresEmployees = (bool) $company->features_employees;
        $this->featuresPayroll = (bool) $company->features_payroll;
        $this->qhsfRatePct = (int) $company->qhsf_rate_bp / 100;
        $this->cnesstRatePct = (int) $company->cnesst_rate_bp / 100;
        $this->wsdrfApplicable = (bool) $company->wsdrf_applicable;
        $this->featuresInventory = (bool) $company->features_inventory;
        $this->featuresFixedAssets = (bool) $company->features_fixed_assets;
        $this->featuresEstimates = (bool) $company->features_estimates;
        $this->featuresSalesOrders = (bool) $company->features_sales_orders;
        $this->featuresPurchaseOrders = (bool) $company->features_purchase_orders;
        $this->featuresRecurringInvoices = (bool) $company->features_recurring_invoices;
        $this->featuresRecurringBills = (bool) $company->features_recurring_bills;
        $this->featuresClasses = (bool) $company->features_classes;
        $this->featuresLocations = (bool) $company->features_locations;
        $this->featuresBudgets = (bool) $company->features_budgets;
        $this->featuresMembership = (bool) $company->features_membership;
        $this->featuresFundraising = (bool) $company->features_fundraising;
        $this->organizationType = $company->organization_type?->value ?? '';
        $this->legalStructure = $company->legal_structure?->value ?? '';
        $this->charityRegistrationNumber = (string) ($company->charity_registration_number ?? '');
        $this->contributionMethod = $company->contribution_method?->value ?? '';
        $this->featuresFunds = (bool) $company->features_funds;
        $this->region = (string) ($company->address_region ?? '');
        $this->addressLine1 = (string) ($company->address_line1 ?? '');
        $this->addressCity = (string) ($company->address_city ?? '');
        $this->phone = (string) ($company->phone ?? '');
        $this->website = (string) ($company->website ?? '');
        $this->email = (string) ($company->email ?? '');
        $this->documentLogoMaxHeight = (int) ($company->document_logo_max_height ?: 64);
        $this->brandName = (string) ($company->brand_name ?? '');
        $this->brandInitials = (string) ($company->brand_initials ?? '');
        $this->brandTextColor = $company->brand_text_color ?: '#ffffff';
        $this->brandBackgroundColor = $company->brand_background_color ?: '#18181b';

        $this->populateCompanyData();
    }

    public function updateCompany(): void
    {
        Gate::authorize('update', $this->companyModel);

        $jurisdiction = $this->companyModel->jurisdiction;
        $regions = $jurisdiction->regions();

        $rules = [
            'companyName' => ['required', 'string', 'max:255', new CompanyName],
            'currencyCode' => ['required', 'string', 'size:3'],
            'fiscalYearStartMonth' => ['required', 'integer', 'between:1,12'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'autoApplyCustomerCredits' => ['boolean'],
            'requireTwoFactor' => ['boolean'],
            'featuresEmployees' => ['boolean'],
            'featuresInventory' => ['boolean'],
            'featuresFixedAssets' => ['boolean'],
            'featuresEstimates' => ['boolean'],
            'featuresSalesOrders' => ['boolean'],
            'featuresPurchaseOrders' => ['boolean'],
            'featuresRecurringInvoices' => ['boolean'],
            'featuresRecurringBills' => ['boolean'],
            'featuresClasses' => ['boolean'],
            'featuresLocations' => ['boolean'],
            'featuresBudgets' => ['boolean'],
            'featuresMembership' => ['boolean'],
            'featuresFundraising' => ['boolean'],
            'warnDuplicateBillNo' => ['boolean'],
            'chequeOffsetX' => ['nullable', 'numeric', 'between:-999,999'],
            'chequeOffsetY' => ['nullable', 'numeric', 'between:-999,999'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressCity' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'email' => ['nullable', 'email', 'max:255'],
            'brandName' => ['nullable', 'string', 'max:255'],
            'brandInitials' => ['nullable', 'string', 'max:4'],
            'brandTextColor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brandBackgroundColor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'documentLogo' => ['nullable', 'image', 'max:2048'],
            'documentLogoMaxHeight' => ['integer', 'min:24', 'max:160'],
        ];

        // The organization type is set once — offered only while it has no value,
        // immutable afterwards (it drives CRA filing guidance and equity labels).
        if ($this->companyModel->organization_type === null) {
            $rules['organizationType'] = ['nullable', Rule::in(array_column(OrganizationType::options($jurisdiction), 'value'))];
        }

        if ($this->companyModel->organization_type?->isNonProfit()) {
            $rules['legalStructure'] = ['nullable', Rule::enum(LegalStructure::class)];
            $rules['contributionMethod'] = ['nullable', Rule::enum(ContributionMethod::class)];
            $rules['charityRegistrationNumber'] = ['nullable', 'string', 'max:32', 'regex:/^\d{9}RR\d{4}$/'];
            $rules['featuresFunds'] = ['boolean'];
        }

        // Payroll is Canada-only.
        if ($jurisdiction === Country::Canada) {
            $rules['featuresPayroll'] = ['boolean'];
            $rules['qhsfRatePct'] = ['numeric', 'min:0', 'max:100'];
            $rules['cnesstRatePct'] = ['numeric', 'min:0', 'max:100'];
            $rules['wsdrfApplicable'] = ['boolean'];
        }

        $rules['region'] = $regions !== []
            ? ['nullable', Rule::in(array_keys($regions))]
            : ['nullable', 'string', 'max:100'];

        $validated = $this->validate($rules);

        $company = DB::transaction(function () use ($validated) {
            $company = Company::whereKey($this->companyModel->id)->lockForUpdate()->firstOrFail();

            $attributes = [
                'name' => $validated['companyName'],
                'currency_code' => strtoupper($validated['currencyCode']),
                'fiscal_year_start_month' => $validated['fiscalYearStartMonth'],
                'timezone' => $validated['timezone'],
                'auto_apply_customer_credits' => (bool) ($validated['autoApplyCustomerCredits'] ?? false),
                'warn_duplicate_bill_no' => (bool) ($validated['warnDuplicateBillNo'] ?? false),
                'cheque_offset_x' => ($validated['chequeOffsetX'] ?? '') !== '' ? (float) $validated['chequeOffsetX'] : null,
                'cheque_offset_y' => ($validated['chequeOffsetY'] ?? '') !== '' ? (float) $validated['chequeOffsetY'] : null,
                'require_two_factor' => (bool) ($validated['requireTwoFactor'] ?? false),
                'features_employees' => (bool) ($validated['featuresEmployees'] ?? false),
                'features_inventory' => (bool) ($validated['featuresInventory'] ?? false),
                'features_fixed_assets' => (bool) ($validated['featuresFixedAssets'] ?? false),
                'features_estimates' => (bool) ($validated['featuresEstimates'] ?? false),
                'features_sales_orders' => (bool) ($validated['featuresSalesOrders'] ?? false),
                'features_purchase_orders' => (bool) ($validated['featuresPurchaseOrders'] ?? false),
                'features_recurring_invoices' => (bool) ($validated['featuresRecurringInvoices'] ?? false),
                'features_recurring_bills' => (bool) ($validated['featuresRecurringBills'] ?? false),
                'features_classes' => (bool) ($validated['featuresClasses'] ?? false),
                'features_locations' => (bool) ($validated['featuresLocations'] ?? false),
                'features_budgets' => (bool) ($validated['featuresBudgets'] ?? false),
                'features_membership' => (bool) ($validated['featuresMembership'] ?? false),
                'features_fundraising' => (bool) ($validated['featuresFundraising'] ?? false),
                'address_region' => $validated['region'] ?: null,
                'address_line1' => filled($validated['addressLine1'] ?? null) ? $validated['addressLine1'] : null,
                'address_city' => filled($validated['addressCity'] ?? null) ? $validated['addressCity'] : null,
                'phone' => filled($validated['phone'] ?? null) ? $validated['phone'] : null,
                'website' => filled($validated['website'] ?? null) ? $validated['website'] : null,
                'email' => filled($validated['email'] ?? null) ? $validated['email'] : null,
                'document_logo_max_height' => (int) ($validated['documentLogoMaxHeight'] ?? 64),
                'brand_name' => filled($validated['brandName'] ?? null) ? $validated['brandName'] : null,
                'brand_initials' => filled($validated['brandInitials'] ?? null) ? mb_strtoupper($validated['brandInitials']) : null,
                'brand_text_color' => $validated['brandTextColor'] ?: null,
                'brand_background_color' => $validated['brandBackgroundColor'] ?: null,
            ];

            // Re-checked against the freshly locked row so two concurrent saves
            // can't both set the once-only organization type.
            if ($company->organization_type === null && ($validated['organizationType'] ?? '') !== '') {
                $attributes['organization_type'] = $validated['organizationType'];
            }

            if ($this->companyModel->organization_type?->isNonProfit()) {
                $attributes['legal_structure'] = ($validated['legalStructure'] ?? '') ?: null;
                $attributes['contribution_method'] = ($validated['contributionMethod'] ?? '') ?: null;
                $attributes['charity_registration_number'] = filled($validated['charityRegistrationNumber'] ?? null)
                    ? $validated['charityRegistrationNumber']
                    : null;
                $attributes['features_funds'] = (bool) ($validated['featuresFunds'] ?? false);
            }

            if ($this->companyModel->jurisdiction === Country::Canada) {
                $attributes['features_payroll'] = (bool) ($validated['featuresPayroll'] ?? false);
                $attributes['qhsf_rate_bp'] = (int) round((float) ($validated['qhsfRatePct'] ?? 0) * 100);
                $attributes['cnesst_rate_bp'] = (int) round((float) ($validated['cnesstRatePct'] ?? 0) * 100);
                $attributes['wsdrf_applicable'] = (bool) ($validated['wsdrfApplicable'] ?? false);
            }

            $logoDisk = StorageDisks::logos();

            if ($this->logo instanceof TemporaryUploadedFile) {
                if ($company->logo_path) {
                    Storage::disk($logoDisk)->delete($company->logo_path);
                }

                $attributes['logo_path'] = $this->logo->store('company-logos', $logoDisk);
            } elseif ($this->removeLogo && $company->logo_path) {
                Storage::disk($logoDisk)->delete($company->logo_path);
                $attributes['logo_path'] = null;
            }

            if ($this->documentLogo instanceof TemporaryUploadedFile) {
                if ($company->document_logo_path) {
                    Storage::disk($logoDisk)->delete($company->document_logo_path);
                }

                $attributes['document_logo_path'] = $this->documentLogo->store('company-logos', $logoDisk);
            } elseif ($this->removeDocumentLogo && $company->document_logo_path) {
                Storage::disk($logoDisk)->delete($company->document_logo_path);
                $attributes['document_logo_path'] = null;
            }

            $company->update($attributes);

            return $company;
        });

        $this->companyModel = $company;
        $this->logo = null;
        $this->removeLogo = false;
        $this->documentLogo = null;
        $this->removeDocumentLogo = false;

        // Seed the default General Fund the first time fund accounting is enabled.
        if ($company->tracksFunds()) {
            app(EnsureDefaultFund::class)->handle($company);
        }

        // Backfill donation/grant revenue + deferred liability accounts when the
        // fundraising module is enabled on a company that lacks them.
        if ($company->tracksFundraising()) {
            app(EnsureFundraisingAccounts::class)->handle($company);
        }

        // Backfill the system payroll accounts when payroll is enabled on a
        // company created before payroll existed.
        if ($company->usesPayroll()) {
            app(EnsurePayrollAccounts::class)->handle($company);
        }

        // Backfill the Inventory Asset + COGS accounts (and wire the company
        // defaults) when inventory is enabled on a company that was created
        // without it.
        if ($company->usesInventory()) {
            app(EnsureInventoryAccounts::class)->handle($company);
        }

        $this->populateCompanyData();

        Flux::toast(variant: 'success', text: __('Company updated.'));

        $this->redirectRoute('companies.edit', ['company' => $this->companyModel->fresh()->slug], navigate: true);
    }

    /**
     * Re-arm the dashboard getting-started tips: enable, clear every checkmark,
     * and un-dismiss so the full tour shows again from the first tip. Owner/Admin
     * only, gated by the same `update` policy as the company form.
     */
    public function restartOnboarding(): void
    {
        Gate::authorize('update', $this->companyModel);

        $this->companyModel->setOnboardingState(['enabled' => true, 'completed' => [], 'dismissed' => false]);

        Flux::toast(variant: 'success', text: __('Getting-started tips will show on the dashboard.'));
    }

    /**
     * Instant-save toggle for AI-phrased daily insights — the per-company
     * opt-in half of the double gate (the operator half is
     * config('insights.ai.enabled') + an Anthropic key). Owner/Admin only,
     * gated by the same `update` policy as the company form.
     */
    public function updatedInsightsAiNarration(bool $value): void
    {
        Gate::authorize('update', $this->companyModel);

        $this->companyModel->setInsightsState(['ai_narration' => $value]);

        Flux::toast(variant: 'success', text: __('Daily insight preference saved.'));
    }

    /**
     * Lock the books through a date (or clear the lock). Owner/Admin only — gated by
     * the same `update` policy as the company form — and guarded by re-entering the
     * actor's account password. Every change is written to the accounting audit log.
     */
    public function confirmLockDate(): void
    {
        Gate::authorize('update', $this->companyModel);

        $validated = $this->validate(
            [
                'lockDate' => ['nullable', 'date'],
                'lockPassword' => ['required', 'string'],
            ],
            attributes: [
                'lockDate' => __('lock date'),
                'lockPassword' => __('password'),
            ],
        );

        if (! Hash::check($validated['lockPassword'], (string) Auth::user()->password)) {
            throw ValidationException::withMessages([
                'lockPassword' => __('The password is incorrect.'),
            ]);
        }

        $newLock = $validated['lockDate'] ?: null;
        $previousLock = $this->companyModel->lock_date?->toDateString();

        $company = DB::transaction(function () use ($newLock) {
            $company = Company::whereKey($this->companyModel->id)->lockForUpdate()->firstOrFail();
            $company->update(['lock_date' => $newLock]);

            return $company;
        });

        $this->companyModel = $company;

        app(AccountingAuditRecorder::class)->record(
            (int) $company->id,
            AuditAction::PeriodLockChanged,
            $company,
            ['from' => $previousLock, 'to' => $newLock],
        );

        $this->lockPassword = '';
        $this->lockDate = $newLock ?? '';
        $this->populateCompanyData();
        $this->dispatch('close-modal', name: 'lock-period');

        Flux::toast(variant: 'success', text: $newLock !== null
            ? __('Books locked through :date.', ['date' => $newLock])
            : __('Period lock removed.'));
    }

    public function editMember(int $userId): void
    {
        Gate::authorize('updateMember', $this->companyModel);

        $membership = $this->companyModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail();

        abort_if($membership->role === CompanyRole::Owner, 403);

        $this->editingMemberId = $userId;
        $this->editMemberName = $this->companyModel->members()->whereKey($userId)->value('name') ?? '';
        $this->editRole = $membership->role->value;
        $this->editSections = $membership->sections ?? [];

        $this->resetValidation();
        $this->dispatch('open-modal', name: 'edit-member');
    }

    public function saveMemberAccess(): void
    {
        $this->updateMember($this->editingMemberId, $this->editRole, $this->editSections);
    }

    /**
     * @param  array<int, string>  $sections
     */
    public function updateMember(int $userId, string $role, array $sections = []): void
    {
        Gate::authorize('updateMember', $this->companyModel);

        $validated = Validator::make(
            ['role' => $role, 'sections' => $sections],
            [
                'role' => ['required', 'string', Rule::enum(CompanyRole::class)->except(CompanyRole::Owner)],
                'sections' => [Rule::requiredIf(fn () => $role === CompanyRole::Custom->value), 'array'],
                'sections.*' => [Rule::in(Section::values())],
            ]
        )->validate();

        $resolvedRole = CompanyRole::from($validated['role']);

        $membership = $this->companyModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail();

        abort_if($membership->role === CompanyRole::Owner, 403);

        $previousRole = $membership->role;
        $previousSections = $membership->sections;
        $newSections = $resolvedRole->usesCustomSections() ? array_values($validated['sections']) : null;

        $membership->update([
            'role' => $resolvedRole,
            'sections' => $newSections,
        ]);

        // Log only an actual privilege change, not a no-op save of the modal.
        if ($previousRole !== $resolvedRole || $previousSections !== $newSections) {
            app(SecurityLogRecorder::class)->record(SecurityEvent::CompanyMemberRoleChanged, Auth::user(), metadata: [
                'company_id' => $this->companyModel->id,
                'target_user_id' => $userId,
                'from_role' => $previousRole->value,
                'to_role' => $resolvedRole->value,
                'from_sections' => $previousSections,
                'to_sections' => $newSections,
            ]);
        }

        // On a privilege reduction — a lower role, or a narrower set of custom
        // sections — invalidate access provisioned under the old level so it
        // doesn't outlive the downgrade.
        $isDowngrade = $resolvedRole->level() < $previousRole->level()
            || ($previousSections !== null && $newSections !== null
                && array_diff($previousSections, $newSections) !== []);

        if ($isDowngrade && ($member = User::find($userId)) !== null) {
            app(AccessRevoker::class)->revokeForDowngrade($member, $this->companyModel);
        }

        $this->populateCompanyData();
        $this->dispatch('close-modal', name: 'edit-member');

        Flux::toast(variant: 'success', text: __('Member access updated.'));
    }

    /**
     * @return array<int, Section>
     */
    #[Computed]
    public function sectionOptions(): array
    {
        return Section::cases();
    }

    private function populateCompanyData(): void
    {
        $user = Auth::user();

        $company = $this->companyModel->fresh();

        $this->companyData = [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'is_personal' => $company->is_personal,
        ];

        $this->members = $company->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
        ])->toArray();

        $this->invitations = $company->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = CompanyRole::assignable();

        $this->isCurrentCompany = $user->isCurrentCompany($company);
    }

    public function render()
    {
        $companyName = $this->companyData['name'] ?? $this->companyModel->name;

        $title = $this->permissions->canUpdateCompany
            ? __('Edit :name', ['name' => $companyName])
            : __('View :name', ['name' => $companyName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): CompanyPermissions
    {
        return Auth::user()->toCompanyPermissions($this->companyModel);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Companies') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Companies')" :subheading="__('Manage your company settings')">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateCompany)
                    <div class="space-y-4">
                        @php
                            $jurisdiction = $companyModel->jurisdiction;
                            $regionOptions = $jurisdiction->regions();
                        @endphp

                        <form wire:submit="updateCompany" class="space-y-6">
                            <flux:input wire:model="companyName" :label="__('Company name')" required data-test="company-name-input" />

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <flux:input wire:model="addressLine1" :label="__('Address')" data-test="company-address-input" />
                                <flux:input wire:model="addressCity" :label="__('City')" data-test="company-city-input" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                @if ($regionOptions !== [])
                                    <flux:select wire:model="region" :label="$jurisdiction->regionLabel()" data-test="company-region-select">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($regionOptions as $code => $name)
                                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:input wire:model="region" :label="$jurisdiction->regionLabel()" data-test="company-region-input" />
                                @endif

                                <flux:tooltip :content="__('Set at creation and cannot be changed.')">
                                    <div>
                                        <flux:input
                                            :value="$jurisdiction->label()"
                                            :label="__('Country')"
                                            disabled
                                            data-test="company-country-display"
                                        />
                                    </div>
                                </flux:tooltip>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <flux:input wire:model="phone" type="tel" :label="__('Telephone number')" data-test="company-phone-input" />
                                <flux:input wire:model="website" type="url" :label="__('Website')" placeholder="https://" data-test="company-website-input" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <flux:input wire:model="email" type="email" :label="__('Email address')" :description="__('Shown on printed documents when the email field is enabled.')" data-test="company-email-input" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <flux:input wire:model="currencyCode" :label="__('Currency')" maxlength="3" required data-test="company-currency-input" />
                                <flux:select wire:model="fiscalYearStartMonth" :label="__('Fiscal year start month')" required data-test="company-fiscal-month-input">
                                    @foreach (range(1, 12) as $monthNumber)
                                        <flux:select.option value="{{ $monthNumber }}">{{ $monthNumber }} — {{ \Carbon\CarbonImmutable::create(null, $monthNumber, 1)->translatedFormat('F') }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

                            @php
                                $timezoneOptions = \App\Models\Company::timezoneOptions();
                            @endphp
                            <flux:select
                                wire:model="timezone"
                                :label="__('Timezone')"
                                :description="__('Transaction dates default to today in this timezone, so a late-evening entry posts on the correct local day. Only affects the default date for new transactions and reports — existing entries are unchanged.')"
                                required
                                data-test="company-timezone-select"
                            >
                                @foreach ($timezoneOptions as $label => $identifier)
                                    <flux:select.option value="{{ $identifier }}">{{ $label }}</flux:select.option>
                                @endforeach
                                @unless (in_array($timezone, $timezoneOptions, true))
                                    <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                                @endunless
                            </flux:select>

                            <flux:switch
                                wire:model="autoApplyCustomerCredits"
                                :label="__('Auto-apply customer credits on invoice post')"
                                :description="__('When a new invoice is posted, automatically consume oldest unapplied customer receipts to pay it down.')"
                                data-test="company-auto-apply-credits-input"
                            />

                            <flux:switch
                                wire:model="warnDuplicateBillNo"
                                :label="__('Warn if duplicate bill number is used')"
                                :description="__('When entering a bill, warn if this supplier already has a bill with the same reference number. You can still save it.')"
                                data-test="company-warn-duplicate-bill-input"
                            />

                            <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-4">
                                <div>
                                    <flux:heading size="sm">{{ __('Getting-started tips') }}</flux:heading>
                                    <flux:subheading>{{ __('Show the dashboard tips that help new users customize and get the most out of the app. Starts the tour again from the first tip.') }}</flux:subheading>
                                </div>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="light-bulb"
                                    wire:click="restartOnboarding"
                                    class="shrink-0"
                                    data-test="company-show-onboarding-tips"
                                >
                                    {{ __('Show tips') }}
                                </flux:button>
                            </div>

                            @if (config('insights.ai.enabled') && config('services.anthropic.key'))
                                <div class="space-y-3 rounded-lg border border-border p-4">
                                    <div>
                                        <flux:heading size="sm">{{ __('Daily insight') }}</flux:heading>
                                        <flux:subheading>{{ __('A short note appears on your dashboard each day — an overdue-invoice nudge, a record month, an upcoming deadline. Choose whether AI writes the wording.') }}</flux:subheading>
                                    </div>
                                    <flux:switch
                                        wire:model.live="insightsAiNarration"
                                        :label="__('Write my daily insight with AI')"
                                        :description="__('Sends only summarized totals — never customer names, transaction details, or descriptions — to Anthropic to write your daily insight. When off, insights still appear using LineLedger\'s built-in wording.')"
                                        data-test="company-insights-ai-input"
                                    />
                                </div>
                            @endif

                            <div class="space-y-3 rounded-lg border border-border p-4">
                                <div>
                                    <flux:heading size="sm">{{ __('Cheque print alignment') }}</flux:heading>
                                    <flux:subheading>{{ __('Nudge printed cheque text to fit your printer or pre-printed stock (points; 1 pt = 1/72 in). Print a cheque, measure the drift, then adjust. Leave blank for the default.') }}</flux:subheading>
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <flux:input type="number" step="0.5" wire:model="chequeOffsetX" :label="__('Horizontal offset')" data-test="company-cheque-offset-x" />
                                    <flux:input type="number" step="0.5" wire:model="chequeOffsetY" :label="__('Vertical offset')" data-test="company-cheque-offset-y" />
                                </div>
                            </div>

                            <flux:switch
                                wire:model="requireTwoFactor"
                                :label="__('Require two-factor authentication for owners & admins')"
                                :description="__('Owners and admins must enable two-factor authentication before they can use this company. They are prompted to set it up; lower-privilege roles are unaffected.')"
                                data-test="company-require-two-factor-input"
                            />

                            <div class="space-y-4 rounded-lg border border-border p-4">
                                <div>
                                    <flux:heading size="sm">{{ __('Features') }}</flux:heading>
                                    <flux:subheading>{{ __('Turn modules on or off. Disabled modules are hidden from the navigation.') }}</flux:subheading>
                                </div>

                                @if ($companyModel->sectionEnabled(Section::Employees))
                                    <flux:switch
                                        wire:model="featuresEmployees"
                                        :label="__('Employees')"
                                        :description="__('Track employees and reimbursements.')"
                                        data-test="company-features-employees-input"
                                    />
                                @endif

                                @if ($companyModel->jurisdiction === Country::Canada && $companyModel->sectionEnabled(Section::Payroll))
                                    <flux:switch
                                        wire:model.live="featuresPayroll"
                                        :label="__('Payroll')"
                                        :description="__('Run Canadian payroll: pay employees, calculate CPP/EI/income tax, write cheques and prepare PD7A/T4/ROE.')"
                                        data-test="company-features-payroll-input"
                                    />
                                @endif

                                @if ($companyModel->sectionEnabled(Section::Inventory))
                                    <flux:switch
                                        wire:model="featuresInventory"
                                        :label="__('Inventory')"
                                        :description="__('Track stock on hand and inventory adjustments.')"
                                        data-test="company-features-inventory-input"
                                    />
                                @endif

                                <flux:switch
                                    wire:model="featuresFixedAssets"
                                    :label="__('Fixed assets')"
                                    :description="__('Track capital assets and depreciation.')"
                                    data-test="company-features-fixed-assets-input"
                                />

                                <flux:switch
                                    wire:model="featuresEstimates"
                                    :label="__('Estimates')"
                                    :description="__('Send customer estimates and convert them to invoices.')"
                                    data-test="company-features-estimates-input"
                                />

                                <flux:switch
                                    wire:model="featuresSalesOrders"
                                    :label="__('Sales orders')"
                                    :description="__('Track customer sales orders and fulfil them with invoices.')"
                                    data-test="company-features-sales-orders-input"
                                />

                                <flux:switch
                                    wire:model="featuresPurchaseOrders"
                                    :label="__('Purchase orders')"
                                    :description="__('Track vendor purchase orders and receive them with bills.')"
                                    data-test="company-features-purchase-orders-input"
                                />

                                <flux:switch
                                    wire:model="featuresRecurringInvoices"
                                    :label="__('Recurring invoices')"
                                    :description="__('Schedule recurring customer invoices.')"
                                    data-test="company-features-recurring-invoices-input"
                                />

                                <flux:switch
                                    wire:model="featuresRecurringBills"
                                    :label="__('Recurring bills')"
                                    :description="__('Schedule recurring vendor bills.')"
                                    data-test="company-features-recurring-bills-input"
                                />

                                <flux:switch
                                    wire:model="featuresClasses"
                                    :label="__('Classes')"
                                    :description="__('Tag transaction lines with a class to slice reports by segment.')"
                                    data-test="company-features-classes-input"
                                />

                                <flux:switch
                                    wire:model="featuresLocations"
                                    :label="__('Locations')"
                                    :description="__('Tag transaction lines with a location to slice reports by site.')"
                                    data-test="company-features-locations-input"
                                />

                                <flux:switch
                                    wire:model="featuresBudgets"
                                    :label="__('Budgets')"
                                    :description="__('Plan account-level budgets and compare them against actuals.')"
                                    data-test="company-features-budgets-input"
                                />

                                <flux:switch
                                    wire:model="featuresMembership"
                                    :label="__('Membership')"
                                    :description="__('Track members and membership levels, and bill recurring dues as invoices.')"
                                    data-test="company-features-membership-input"
                                />

                                @if ($companyModel->sectionEnabled(Section::Fundraising))
                                    <flux:switch
                                        wire:model="featuresFundraising"
                                        :label="__('Donations & grants')"
                                        :description="__('Record donation and grant income, track restricted funding, and issue donation receipts.')"
                                        data-test="company-features-fundraising-input"
                                    />
                                @endif
                            </div>

                            @if ($companyModel->jurisdiction === Country::Canada && $featuresPayroll)
                                <div class="space-y-4 rounded-lg border border-border p-4" data-test="company-quebec-payroll-card">
                                    <div>
                                        <flux:heading size="sm">{{ __('Quebec payroll') }}</flux:heading>
                                        <flux:subheading>{{ __('Employer levies for Quebec employees, remitted to Revenu Québec. Leave at 0 if you have none.') }}</flux:subheading>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <flux:input
                                            type="number"
                                            step="0.01"
                                            wire:model="qhsfRatePct"
                                            :label="__('QHSF rate (%)')"
                                            :description="__('Health Services Fund, on Quebec gross pay.')"
                                            data-test="company-qhsf-rate-input"
                                        />
                                        <flux:input
                                            type="number"
                                            step="0.01"
                                            wire:model="cnesstRatePct"
                                            :label="__('CNESST rate (%)')"
                                            :description="__('Occupational health & safety, on Quebec insurable earnings.')"
                                            data-test="company-cnesst-rate-input"
                                        />
                                    </div>

                                    <flux:switch
                                        wire:model="wsdrfApplicable"
                                        :label="__('Subject to the 1% workforce skills development levy (WSDRF)')"
                                        :description="__('Reconciled on the RL-1 Summary: 1% of Quebec payroll less recorded eligible training.')"
                                        data-test="company-wsdrf-applicable-input"
                                    />
                                </div>
                            @endif

                            <div class="space-y-4 rounded-lg border border-border p-4" data-test="company-organization-type-card">
                                <div>
                                    <flux:heading size="sm">{{ __('Organization type') }}</flux:heading>
                                    <flux:subheading>{{ __('How your organization is legally structured. Determines which CRA returns apply and how equity is presented.') }}</flux:subheading>
                                </div>

                                @if ($companyModel->organization_type === null)
                                    <flux:select
                                        wire:model="organizationType"
                                        :label="__('Organization type')"
                                        :description="__('This can be set only once — it cannot be changed after saving.')"
                                        data-test="company-organization-type-select"
                                    >
                                        <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                                        @foreach (OrganizationType::options($companyModel->jurisdiction) as $option)
                                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }} — {{ $option['description'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <div data-test="company-organization-type-display">
                                        <flux:text class="font-medium">{{ $companyModel->organization_type->label() }}</flux:text>
                                        <flux:text class="mt-1 text-sm text-muted-foreground">{{ __('The organization type is set once and cannot be changed.') }}</flux:text>
                                    </div>
                                @endif
                            </div>

                            @if ($companyModel->organization_type?->isNonProfit())
                                <div class="space-y-4 rounded-lg border border-border p-4" data-test="company-nonprofit-card">
                                    <div>
                                        <flux:heading size="sm">{{ __('Non-profit & charity') }}</flux:heading>
                                        <flux:subheading>{{ __('Your legal tier and how you account for restricted contributions.') }}</flux:subheading>
                                    </div>

                                    <flux:select wire:model.live="legalStructure" :label="__('Legal structure')" data-test="company-legal-structure-select">
                                        <flux:select.option value="">{{ __('Not specified') }}</flux:select.option>
                                        @foreach (LegalStructure::options() as $option)
                                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if (LegalStructure::tryFrom($legalStructure)?->requiresCharityRegistration())
                                        <flux:input
                                            wire:model="charityRegistrationNumber"
                                            :label="__('CRA charity registration number')"
                                            placeholder="123456789RR0001"
                                            :description="__('Your Business Number with RR program account. Required to issue official donation receipts and file the T3010.')"
                                            data-test="company-charity-number-input"
                                        />
                                    @endif

                                    <flux:select wire:model.live="contributionMethod" :label="__('Contribution accounting method')" data-test="company-contribution-method-select">
                                        <flux:select.option value="">{{ __('Not specified') }}</flux:select.option>
                                        @foreach (ContributionMethod::options() as $option)
                                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if ($contributionMethod === ContributionMethod::RestrictedFund->value)
                                        <flux:switch
                                            wire:model="featuresFunds"
                                            :label="__('Fund accounting')"
                                            :description="__('Tag transaction lines with a fund and report per fund. Enabling seeds a default General Fund.')"
                                            data-test="company-features-funds-input"
                                        />
                                    @endif

                                    <p class="text-sm text-muted-foreground">{{ __('Changing the contribution method only affects how new restricted contributions are recorded and how statements present — it never reclassifies existing entries.') }}</p>
                                </div>
                            @endif

                            <div class="space-y-4 rounded-lg border border-border p-4">
                                <div>
                                    <flux:heading size="sm">{{ __('Sidebar branding') }}</flux:heading>
                                    <flux:subheading>{{ __('Controls the icon and label shown above the company switcher.') }}</flux:subheading>
                                </div>

                                @php
                                    $previewLogo = $logo
                                        ? $logo->temporaryUrl()
                                        : (! $removeLogo ? $companyModel->logoUrl() : null);
                                    $previewInitials = $brandInitials !== ''
                                        ? mb_strtoupper($brandInitials)
                                        : $companyModel->brandInitials();
                                    $previewName = $brandName !== '' ? $brandName : $companyName;
                                @endphp

                                <div class="flex items-center gap-3 rounded-md bg-muted p-3">
                                    <div
                                        class="flex aspect-square size-10 items-center justify-center overflow-hidden rounded-md"
                                        style="background-color: {{ $brandBackgroundColor ?: '#18181b' }}; color: {{ $brandTextColor ?: '#ffffff' }};"
                                    >
                                        @if ($previewLogo)
                                            <img src="{{ $previewLogo }}" alt="{{ $previewName }}" class="size-full object-cover" />
                                        @else
                                            <span class="text-sm font-semibold leading-none">{{ $previewInitials }}</span>
                                        @endif
                                    </div>
                                    <div class="text-sm font-semibold">{{ $previewName }}</div>
                                </div>

                                <flux:input wire:model.live.debounce.400ms="brandName" :label="__('Display name')" :description="__('Shown in the sidebar. Leave blank to use the company name.')" data-test="company-brand-name-input" />

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <flux:input wire:model.live.debounce.400ms="brandInitials" :label="__('Initials')" maxlength="4" data-test="company-brand-initials-input" />
                                    <flux:field>
                                        <flux:label>{{ __('Text color') }}</flux:label>
                                        <input type="color" wire:model.live="brandTextColor" class="h-10 w-full cursor-pointer rounded-md border border-border bg-card" data-test="company-brand-text-color-input" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>{{ __('Background color') }}</flux:label>
                                        <input type="color" wire:model.live="brandBackgroundColor" class="h-10 w-full cursor-pointer rounded-md border border-border bg-card" data-test="company-brand-bg-color-input" />
                                    </flux:field>
                                </div>

                                <flux:input type="file" wire:model="logo" accept="image/*" :label="__('Logo')" :description="__('PNG, JPG, or SVG up to 2 MB. Replaces the initials when set.')" data-test="company-logo-input" />

                                @if ($companyModel->logo_path && ! $logo)
                                    <flux:checkbox wire:model.live="removeLogo" :label="__('Remove current logo on save')" data-test="company-logo-remove" />
                                @endif
                            </div>

                            <div class="space-y-4 rounded-lg border border-border p-4">
                                <div>
                                    <flux:heading size="sm">{{ __('Document logo') }}</flux:heading>
                                    <flux:subheading>{{ __('The logo printed on invoices, estimates and other PDF documents, in place of the company name. Falls back to the sidebar logo if left blank.') }}</flux:subheading>
                                </div>

                                @php
                                    $documentLogoPreview = $documentLogo
                                        ? $documentLogo->temporaryUrl()
                                        : (! $removeDocumentLogo && $companyModel->document_logo_path
                                            ? Storage::disk(StorageDisks::logos())->url($companyModel->document_logo_path)
                                            : null);
                                @endphp

                                @if ($documentLogoPreview)
                                    <div class="flex items-center rounded-md bg-muted p-3">
                                        <img src="{{ $documentLogoPreview }}" alt="{{ $companyName }}" style="max-height: {{ $documentLogoMaxHeight }}px;" class="w-auto object-contain" />
                                    </div>
                                @endif

                                <flux:input type="file" wire:model="documentLogo" accept="image/*" :label="__('Logo image')" :description="__('PNG, JPG, or SVG up to 2 MB.')" data-test="company-document-logo-input" />

                                <flux:input type="number" wire:model="documentLogoMaxHeight" :label="__('Logo height on documents (px)')" min="24" max="160" :description="__('Recommended 48–80px. Controls how large the logo prints on documents.')" data-test="company-document-logo-height-input" />

                                @if ($companyModel->document_logo_path && ! $documentLogo)
                                    <flux:checkbox wire:model.live="removeDocumentLogo" :label="__('Remove current document logo on save')" data-test="company-document-logo-remove" />
                                @endif
                            </div>

                            <flux:button variant="primary" type="submit" data-test="company-save-button">
                                {{ __('Save') }}
                            </flux:button>
                        </form>
                    </div>
                @else
                    <div>
                        <flux:heading>{{ $companyData['name'] }}</flux:heading>
                    </div>
                @endif
            </div>

            @if ($this->permissions->canUpdateCompany)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Close the books') }}</flux:heading>
                        <flux:subheading>{{ __('Lock the books through a date to stop new, edited, or voided transactions on or before it. Requires your account password.') }}</flux:subheading>
                    </div>

                    <flux:card class="flex flex-wrap items-center justify-between gap-4">
                        @if ($companyModel->lock_date)
                            <div class="flex items-center gap-2">
                                <flux:icon.lock-closed variant="solid" class="text-amber-600" />
                                <div>
                                    <flux:text class="font-medium">{{ __('Books locked through :date', ['date' => $companyModel->lock_date->toDateString()]) }}</flux:text>
                                    <flux:text size="sm" class="text-muted-foreground">{{ __('Transactions on or before this date are frozen.') }}</flux:text>
                                </div>
                            </div>
                        @else
                            <flux:text class="text-muted-foreground">{{ __('The books are open. No closing date is set.') }}</flux:text>
                        @endif

                        <flux:modal.trigger name="lock-period">
                            <flux:button variant="primary" size="sm" icon="lock-closed" data-test="lock-period-button">
                                {{ $companyModel->lock_date ? __('Change lock date') : __('Close the books') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </flux:card>
                </div>
            @endif

            @if ($this->permissions->canUpdateCompany)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Online payments') }}</flux:heading>
                        <flux:subheading>{{ __('Connect Stripe so customers can pay invoices by card in the payment portal.') }}</flux:subheading>
                    </div>

                    <flux:card class="flex flex-wrap items-center justify-between gap-4">
                        @if ($companyModel->stripeConnectionNeedsAttention())
                            <div class="flex items-center gap-2">
                                <flux:icon.exclamation-triangle variant="solid" class="text-amber-500" />
                                <div>
                                    <flux:text class="font-medium">{{ __('Stripe connection needs attention') }}</flux:text>
                                    <flux:text size="sm" class="text-muted-foreground">{{ __('Card payments are paused until you reconnect your Stripe account.') }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button
                                    :href="route('settings.stripe.connect', ['company' => $companyModel->slug])"
                                    variant="primary"
                                    size="sm"
                                    icon="credit-card"
                                    data-test="stripe-reconnect"
                                >
                                    {{ __('Reconnect Stripe') }}
                                </flux:button>
                                <form method="POST" action="{{ route('settings.stripe.disconnect', ['company' => $companyModel->slug]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" variant="ghost" size="sm" data-test="stripe-disconnect">{{ __('Disconnect') }}</flux:button>
                                </form>
                            </div>
                        @elseif ($companyModel->hasStripeConnected())
                            <div class="flex items-center gap-2">
                                <flux:icon.check-badge variant="solid" class="text-green-600" />
                                <div>
                                    <flux:text class="font-medium">{{ __('Stripe connected') }}</flux:text>
                                    <flux:text size="sm" class="text-muted-foreground">{{ $companyModel->stripe_account_id }}</flux:text>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('settings.stripe.disconnect', ['company' => $companyModel->slug]) }}">
                                @csrf
                                @method('DELETE')
                                <flux:button type="submit" variant="ghost" size="sm" data-test="stripe-disconnect">{{ __('Disconnect') }}</flux:button>
                            </form>
                        @else
                            <flux:text class="text-muted-foreground">{{ __('Not connected.') }}</flux:text>
                            <flux:button
                                :href="route('settings.stripe.connect', ['company' => $companyModel->slug])"
                                variant="primary"
                                size="sm"
                                icon="credit-card"
                                data-test="stripe-connect"
                            >
                                {{ __('Connect Stripe') }}
                            </flux:button>
                        @endif
                    </flux:card>
                </div>
            @endif

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading>{{ __('Company members') }}</flux:heading>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <flux:subheading>{{ __('Manage who belongs to this company') }}</flux:subheading>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <flux:modal.trigger name="invite-member">
                            <flux:button variant="primary" icon="user-plus" data-test="invite-member-button">
                                {{ __('Invite member') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div class="flex items-center justify-between rounded-lg border border-border bg-card p-4" data-test="member-row">
                            <div class="flex items-center gap-4">
                                <flux:avatar :name="$member['name']" :initials="strtoupper(substr($member['name'], 0, 1))" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-muted-foreground">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>

                                @if ($member['role'] !== 'owner' && $this->permissions->canUpdateMember)
                                    <flux:button
                                        variant="outline"
                                        size="sm"
                                        icon="pencil-square"
                                        wire:click="editMember({{ $member['id'] }})"
                                        data-test="member-edit-button"
                                    >
                                        {{ __('Edit') }}
                                    </flux:button>
                                @endif

                                @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                                    <flux:modal.trigger name="remove-member-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Remove member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="member-remove-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                            <livewire:pages::companies.remove-member-modal
                                :company="$companyModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-border bg-card p-4" data-test="invitation-row">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-muted">
                                        <flux:icon name="envelope" class="text-muted-foreground" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $invitation['email'] }}</div>
                                        <flux:text class="text-sm text-muted-foreground">{{ $invitation['role_label'] }}</flux:text>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <flux:modal.trigger name="cancel-invitation-{{ $invitation['code'] }}">
                                        <flux:tooltip :content="__('Cancel invitation')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="invitation-cancel-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::companies.cancel-invitation-modal
                                    :company="$companyModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteCompany && ! $companyData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Delete company') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently delete your company') }}</flux:subheading>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <flux:modal.trigger name="delete-company">
                            <flux:button variant="danger" data-test="delete-company-button">
                                {{ __('Delete company') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::companies.invite-member-modal :company="$companyModel" />
    @endif

    @if ($this->permissions->canUpdateCompany)
        <flux:modal name="lock-period" focusable class="max-w-md">
            <form wire:submit="confirmLockDate" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Close the books') }}</flux:heading>
                    <flux:subheading>{{ __('Transactions dated on or before the lock date can no longer be posted, edited, or voided.') }}</flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:input
                        type="date"
                        wire:model="lockDate"
                        :label="__('Lock date')"
                        :description="__('Leave blank to remove the lock and reopen the books.')"
                        data-test="lock-period-date"
                    />

                    <flux:input
                        type="password"
                        wire:model="lockPassword"
                        :label="__('Your account password')"
                        autocomplete="current-password"
                        data-test="lock-period-password"
                    />
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="lock-period-submit">{{ __('Confirm') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->permissions->canUpdateMember)
        <flux:modal name="edit-member" focusable class="max-w-lg">
            <form wire:submit="saveMemberAccess" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Edit member access') }}</flux:heading>
                    <flux:subheading>{{ $editMemberName }}</flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:select wire:model.live="editRole" :label="__('Role')" data-test="edit-member-role">
                        @foreach ($availableRoles as $role)
                            <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }} — {{ $role['description'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($editRole === \App\Enums\CompanyRole::Custom->value)
                        <flux:fieldset>
                            <flux:legend>{{ __('Sections') }}</flux:legend>
                            <flux:description>{{ __('Choose which sections this member can access.') }}</flux:description>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($this->sectionOptions as $section)
                                    <flux:checkbox
                                        wire:model="editSections"
                                        value="{{ $section->value }}"
                                        :label="$section->label()"
                                        data-test="edit-section-{{ $section->value }}"
                                    />
                                @endforeach
                            </div>
                            <flux:error name="sections" />
                        </flux:fieldset>
                    @endif
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="edit-member-submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->permissions->canDeleteCompany && ! $companyData['is_personal'])
        <livewire:pages::companies.delete-company-modal :company="$companyModel" />
    @endif
</section>
