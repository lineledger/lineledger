<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\AccountSubtype;
use App\Enums\ContributionMethod;
use App\Enums\Country;
use App\Enums\DataMigrationMode;
use App\Enums\Industry;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Rules\CompanyName;
use App\Services\Migration\QuickBooksMigrationService;
use App\Support\Defaults\ChartTemplateBuilder;
use App\Support\SiteSettings;
use App\Support\Tax\ProvincialSalesTax;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.onboarding'), Title('Setup your organization')] class extends Component
{
    // Mirror the step into the URL with pushState (history: true) so the browser
    // Back/Forward buttons walk the wizard instead of leaving the page. except: 1
    // keeps the first slide at a clean /welcome. mount() forces step 1 on a fresh
    // page load (data is in-memory only), so a refresh or deep link can't strand
    // the user on a later step with empty fields — popstate within a session does
    // not re-run mount(), so back/forward navigation is unaffected.
    #[Url(history: true, except: 1)]
    public int $step = 1;

    public int $maxStep = 8;

    /**
     * Starting timezone offered to every new company. Eastern (Canada/US) is a
     * sensible default for our user base; owners refine it before continuing.
     */
    private const DEFAULT_TIMEZONE = 'America/New_York';

    /**
     * The top-level org choice that collapses the three non-profit org types
     * (club, non-profit, charity) into one. When selected, the legal-structure
     * radio below refines it into the precise OrganizationType.
     */
    private const NONPROFIT_CATEGORY = 'nonprofit';

    // Step 1 — company info
    public string $companyName = '';

    public string $country = '';

    public string $region = '';

    public string $currency = '';

    public string $timezone = '';

    // Flips true once the browser has reported its IANA timezone, so revisiting
    // step 1 never re-clobbers a choice the owner made by hand.
    public bool $timezoneAutodetected = false;

    public int $fiscalYearStartMonth = 1;

    // Step 2 — organization type. $orgCategory is what the top-level radio binds
    // to; $organizationType is derived from it (and, for non-profits, from the
    // legal structure) and is the value actually persisted + fed to the chart.
    public string $orgCategory = OrganizationType::SoleProprietorship->value;

    public string $organizationType = OrganizationType::SoleProprietorship->value;

    // Step 2 — non-profit tier (only used when the org type is a non-profit/charity)
    public string $legalStructure = '';

    public string $charityRegistrationNumber = '';

    public string $contributionMethod = ContributionMethod::Deferral->value;

    // Step 3 — industry + chart mode
    public string $industry = Industry::General->value;

    public string $chartMode = 'standard'; // 'standard' | 'copy' | 'minimal'

    // Step 3 — when chartMode is 'copy', the existing company to clone the chart
    // of accounts from. Authorized + resolved through sourceCompany().
    public ?int $sourceCompanyId = null;

    // Step 4 — features
    public bool $featuresInventory = false;

    public bool $featuresEmployees = false;

    public bool $featuresPayroll = false;

    public bool $featuresFixedAssets = false;

    public bool $featuresEstimates = false;

    public bool $featuresSalesOrders = false;

    public bool $featuresRecurringInvoices = false;

    public bool $featuresRecurringBills = false;

    public bool $featuresClasses = false;

    public bool $featuresLocations = false;

    public bool $featuresBudgets = true;

    public bool $featuresMembership = false;

    public bool $featuresFundraising = false;

    // Step 5 — sales tax
    public bool $chargesTax = true;

    // Whether the company also charges provincial sales tax (PST/RST). Only
    // meaningful — and only surfaced — for provinces that levy it (see
    // provincialSalesTax()); ignored everywhere else.
    public bool $chargesPst = true;

    // GST/HST (federal) account number — stored on the company and the CRA agency.
    public string $taxNumber = '';

    // Provincial sales-tax (PST/RST/QST) account number — stored on the
    // provincial tax agency. Only collected for provinces that levy it.
    public string $pstNumber = '';

    // Step 6 — how to start: fresh vs import vs restore-backup
    public string $startMode = 'fresh'; // 'fresh' | 'import' | 'restore-backup'

    public string $importMode = ''; // '' | 'opening_balance' | 'full_history'

    public string $startDate = '';

    // Step 7 — chart preview selection
    /** @var array<string, bool> code => selected */
    public array $selectedAccounts = [];

    // Set once the company is created on the import branch; flips the wizard into the embedded import phase.
    // #[Locked]: only beginImport() may set this. Without it the property is client-settable via a
    // /livewire/update syncInput, and company() resolves it through an unscoped Company::find() — so a
    // forged value would mount the importer against an arbitrary tenant (cross-tenant read + ledger write).
    #[Locked]
    public ?int $createdCompanyId = null;

    public function mount(): void
    {
        // Only the first-company welcome path bounces existing users to the picker.
        // The "add a company" entry (companies.setup) is reachable with companies.
        if (request()->routeIs('welcome.create-company') && Auth::user()->companies()->exists()) {
            $this->redirectRoute('companies.picker', navigate: false);

            return;
        }

        // A fresh page load carries no in-memory answers, so the only valid entry
        // point is the first step — override any ?step=N left in the URL (refresh,
        // bookmark, deep link). This runs after the Url attribute hydrates the
        // property and only on initial mount, so in-session Back/Forward still work.
        $this->step = 1;

        $this->startDate = now()->toDateString();

        // Prefill the country from Cloudflare's edge geolocation header when the
        // app is fronted by Cloudflare. Only our two supported jurisdictions are
        // honoured; anything else falls back to the host's national TLD for
        // self-hosted/non-Cloudflare deploys. We only trust the confident
        // .ca → Canada signal — a non-.ca host (a self-hosted .com, localhost) is
        // not evidence of the US, so it leaves the field blank for the owner to
        // pick explicitly. Country is immutable after creation.
        $geoCountry = match (mb_strtoupper((string) request()->header('CF-IPCountry'))) {
            'CA' => Country::Canada,
            'US' => Country::UnitedStates,
            default => null,
        };

        $hostCountry = Country::fromHost(request()->getHost()) === Country::Canada
            ? Country::Canada
            : null;

        $this->country = ($geoCountry ?? $hostCountry)?->value ?? '';

        $this->currency = $this->defaultCurrencyForCountry($this->countryEnum());
        $this->timezone = self::DEFAULT_TIMEZONE;

        $this->applyIndustryFeatureDefaults();
    }

    /**
     * Re-suggest the Features step toggles whenever the industry changes. The
     * owner can still flip any module afterwards; this only sets the starting
     * point. Picking the same industry again leaves manual changes untouched
     * (no Livewire update fires when the value is unchanged).
     */
    public function updatedIndustry(): void
    {
        $this->applyIndustryFeatureDefaults();
    }

    private function applyIndustryFeatureDefaults(): void
    {
        $features = (Industry::tryFrom($this->industry) ?? Industry::General)->recommendedFeatures();

        $this->featuresInventory = $features['inventory'];
        $this->featuresEmployees = $features['employees'];
        $this->featuresFixedAssets = $features['fixed_assets'];
        $this->featuresEstimates = $features['estimates'];
        $this->featuresSalesOrders = $features['sales_orders'];
        $this->featuresRecurringInvoices = $features['recurring_invoices'];
        $this->featuresRecurringBills = $features['recurring_bills'];
        $this->featuresClasses = $features['classes'];
        $this->featuresLocations = $features['locations'];
        $this->featuresBudgets = $features['budgets'];
        $this->featuresMembership = $features['membership'];
        $this->featuresFundraising = $features['fundraising'];
    }

    public function countryEnum(): ?Country
    {
        return Country::tryFrom($this->country);
    }

    /**
     * Base currency we pre-select for a jurisdiction. Unsupported/blank countries
     * resolve to '', forcing an explicit choice.
     */
    private function defaultCurrencyForCountry(?Country $country): string
    {
        return match ($country) {
            Country::Canada => 'CAD',
            Country::UnitedStates => 'USD',
            default => '',
        };
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function regions(): array
    {
        return $this->countryEnum()?->regions() ?? [];
    }

    /**
     * @return array<int, array{label: string}>
     */
    #[Computed]
    public function steps(): array
    {
        return [
            1 => ['label' => __('Organization info')],
            2 => ['label' => __('Organization')],
            3 => ['label' => __('Industry & accounts')],
            4 => ['label' => __('Features')],
            5 => ['label' => __('Sales tax')],
            6 => ['label' => __('How to start')],
            7 => ['label' => __('Review accounts')],
            8 => ['label' => __('Confirm')],
        ];
    }

    /**
     * The proposed chart, recomputed live from the current answers. Single source
     * of truth shared with seeding so the preview never diverges from what is
     * created.
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool, description?: string, locked: bool, default_selected: bool}>
     */
    #[Computed]
    public function chartPreview(): array
    {
        if ($this->chartMode === 'copy') {
            $company = $this->sourceCompany();

            if ($company === null) {
                return [];
            }

            $accounts = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderBy('code')
                ->get();

            return ChartTemplateBuilder::fromCompanyAccounts($accounts);
        }

        return app(ChartTemplateBuilder::class)->build(
            jurisdiction: $this->countryEnum() ?? Country::Canada,
            industry: Industry::tryFrom($this->industry) ?? Industry::General,
            orgType: OrganizationType::tryFrom($this->organizationType) ?? OrganizationType::Other,
            minimal: $this->chartMode === 'minimal',
            features: $this->chartFeatureGates(),
            region: $this->region ?: null,
        );
    }

    /**
     * The companies the owner can copy a chart of accounts from — every company
     * they're a member of (any role).
     *
     * @return \Illuminate\Support\Collection<int, Company>
     */
    #[Computed]
    public function sourceCompanies(): \Illuminate\Support\Collection
    {
        return Auth::user()->companies()->orderBy('name')->get();
    }

    /**
     * The chosen copy source, resolved through the user's memberships so a
     * tampered id for a company they don't belong to returns null (and fails
     * step-3 validation).
     */
    public function sourceCompany(): ?Company
    {
        return $this->sourceCompanyId === null
            ? null
            : Auth::user()->companies()->whereKey($this->sourceCompanyId)->first();
    }

    public function updatedSourceCompanyId(): void
    {
        unset($this->chartPreview);
    }

    /**
     * The provincial sales tax for the chosen province, or null when the province
     * has none (HST or GST-only). Drives the step-5 PST toggle and the matching
     * payable account in the chart preview.
     */
    public function provincialSalesTax(): ?ProvincialSalesTax
    {
        return $this->countryEnum() === Country::Canada
            ? ProvincialSalesTax::forRegion($this->region ?: null)
            : null;
    }

    /**
     * Record each tax account number on its agency: the GST/HST number on the
     * federal agency (payable account 2200) and the provincial number on the
     * PST/QST agency (payable account 2210). The agencies are created by
     * CompanyObserver during company creation, so this runs afterwards.
     */
    private function applyTaxRegistrationNumbers(Company $company): void
    {
        $agencyForPayable = function (string $accountCode) use ($company): ?TaxAgency {
            $account = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('code', $accountCode)
                ->first();

            return $account
                ? TaxAgency::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('payable_account_id', $account->id)
                    ->first()
                : null;
        };

        if ($this->taxNumber !== '') {
            $agencyForPayable('2200')?->update(['registration_number' => $this->taxNumber]);
        }

        if ($this->pstNumber !== '') {
            $agencyForPayable('2210')?->update(['registration_number' => $this->pstNumber]);
        }
    }

    /**
     * The wizard answers that shape the chart: unchecking inventory drops
     * Inventory Asset + COGS (1400/5000), unchecking "I charge sales tax" drops
     * the tax-payable account (2200), disabling employees drops the
     * employee-reimbursements payable (2300), and disabling fixed assets drops
     * every capital-asset + accumulated-depreciation account. "pst" adds the
     * provincial sales-tax payable for provinces that levy it. Omitting an
     * account here is preferable to seeding one the company will never post to;
     * enabling the capability later backfills it.
     *
     * @return array<string, bool>
     */
    private function chartFeatureGates(): array
    {
        return [
            'inventory' => $this->featuresInventory,
            'sales_tax' => $this->chargesTax,
            'employees' => $this->featuresEmployees,
            'fixed_assets' => $this->featuresFixedAssets,
            'pst' => $this->chargesPst && $this->provincialSalesTax() !== null,
        ];
    }

    /**
     * The equity-section heading for the chosen organization type (e.g. "Net
     * Assets" for a non-profit, "Partners' Equity" for a partnership). Used to
     * relabel only the Equity group in the chart preview.
     */
    public function equitySectionLabel(): string
    {
        return OrganizationType::tryFrom($this->organizationType)?->equitySectionLabel()
            ?? __('Equity');
    }

    /**
     * The company created on the import branch (null until beginImport runs).
     * Drives the embedded migration component.
     */
    #[Computed]
    public function company(): ?Company
    {
        return $this->createdCompanyId ? Company::find($this->createdCompanyId) : null;
    }

    public function updatedCountry(): void
    {
        $this->currency = $this->defaultCurrencyForCountry($this->countryEnum());
        $this->region = '';

        // Registered charity is CRA-specific; if the owner switches to a
        // jurisdiction where the chosen tier isn't offered, fall back to the
        // non-profit corporation tier and re-derive the organization type.
        if ($this->orgCategory === self::NONPROFIT_CATEGORY
            && LegalStructure::tryFrom($this->legalStructure)?->availableIn($this->countryEnum()) !== true) {
            $this->legalStructure = LegalStructure::NonProfitCorporation->value;
            $this->syncOrganizationTypeFromLegalStructure();
        }

        // Timezone is intentionally left untouched: it defaults to Eastern on
        // mount and is the owner's to set, not something a country change clobbers.

        unset($this->chartPreview, $this->regions);
    }

    /**
     * Replace the Eastern default with the browser's own IANA timezone on first
     * load, and seed the province/state from that same zone when it's region-
     * specific. Runs once (guarded by $timezoneAutodetected) so navigating back
     * to step 1 never overrides a zone the owner picked. Unknown/invalid ids from
     * the client are ignored, leaving the default in place. Zones outside the
     * curated list still render via the select's fallback option.
     */
    public function detectTimezone(string $tz): void
    {
        if ($this->timezoneAutodetected) {
            return;
        }

        $this->timezoneAutodetected = true;

        if ($tz === '' || ! in_array($tz, timezone_identifiers_list(), true)) {
            return;
        }

        $this->timezone = $tz;

        // The same browser zone narrows the province/state when it's region-
        // specific (America/Vancouver, America/Halifax…); ambiguous zones leave
        // it blank. Only fill a still-empty field so a manual pick — or an answer
        // restored by navigating Back to step 1 — is never clobbered.
        if ($this->region === '') {
            $this->region = $this->countryEnum()?->regionForTimezone($tz) ?? '';
        }
    }

    public function next(): mixed
    {
        $rules = $this->rulesForStep($this->step);

        if ($rules !== []) {
            $this->validate($rules);
        }

        // Restore branch is a top-level redirect: the new company is created
        // by the restore flow itself, not the wizard, so we never enter the
        // chart-of-accounts or confirm steps.
        if ($this->step === 6 && $this->startMode === 'restore-backup') {
            return $this->redirectRoute('companies.restore', navigate: true);
        }

        if ($this->step < $this->maxStep) {
            $this->step++;
        }

        if ($this->step === 7) {
            $this->syncSelectedAccounts();
        }

        return null;
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function jumpTo(int $step): void
    {
        // Only allow jumping back to an already-completed step; forward movement
        // must go through next() so each step is validated.
        if ($step >= 1 && $step < $this->step) {
            $this->step = $step;
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Whether the collapsed "non-profit or charity" choice is selected, which
     * reveals the legal-structure refinement below.
     */
    #[Computed]
    public function nonProfitSelected(): bool
    {
        return $this->orgCategory === self::NONPROFIT_CATEGORY;
    }

    /**
     * React to the top-level choice. For a for-profit/other category the category
     * IS the organization type. For the non-profit category we default the legal
     * tier (the radio below refines it) and clear it back out otherwise.
     */
    public function updatedOrgCategory(): void
    {
        if ($this->orgCategory === self::NONPROFIT_CATEGORY) {
            // Keep an existing, still-valid tier; otherwise default to a non-profit
            // corporation (offered in every jurisdiction).
            if (LegalStructure::tryFrom($this->legalStructure)?->availableIn($this->countryEnum()) !== true) {
                $this->legalStructure = LegalStructure::NonProfitCorporation->value;
            }

            $this->syncOrganizationTypeFromLegalStructure();

            return;
        }

        // For-profit / other: the category is itself a valid OrganizationType.
        $this->organizationType = $this->orgCategory;
        $this->legalStructure = '';
        $this->charityRegistrationNumber = '';
        $this->contributionMethod = ContributionMethod::Deferral->value;
    }

    /**
     * The legal-structure radio is the source of truth for the precise non-profit
     * org type, so re-derive it whenever the tier changes.
     */
    public function updatedLegalStructure(): void
    {
        if ($this->orgCategory === self::NONPROFIT_CATEGORY) {
            $this->syncOrganizationTypeFromLegalStructure();
        }
    }

    /**
     * Map the chosen legal tier onto the persisted OrganizationType and tidy the
     * dependent fields: an unincorporated association (club) is deferral-only, and
     * the CRA number is irrelevant outside a registered charity.
     */
    private function syncOrganizationTypeFromLegalStructure(): void
    {
        $tier = LegalStructure::tryFrom($this->legalStructure);

        if ($tier === null) {
            return;
        }

        $this->organizationType = $tier->toOrganizationType()->value;

        if ($tier === LegalStructure::UnincorporatedAssociation) {
            $this->contributionMethod = ContributionMethod::Deferral->value;
        }

        if (! $tier->requiresCharityRegistration()) {
            $this->charityRegistrationNumber = '';
        }
    }

    /**
     * The top-level organization choices: the for-profit/other types as-is, with
     * the three non-profit types collapsed into a single "non-profit or charity"
     * entry inserted just before "Other".
     *
     * @return list<array{value: string, label: string, description: string, help: string}>
     */
    #[Computed]
    public function orgTypeChoices(): array
    {
        $country = $this->countryEnum();

        $forProfit = array_filter(
            OrganizationType::options($country),
            fn (array $o) => ! in_array($o['value'], [
                OrganizationType::Club->value,
                OrganizationType::NonProfit->value,
                OrganizationType::Charity->value,
            ], true),
        );

        $choices = [];

        foreach ($forProfit as $option) {
            if ($option['value'] === OrganizationType::Other->value) {
                $choices[] = $this->nonProfitChoice($country);
            }

            $choices[] = $option;
        }

        return $choices;
    }

    /**
     * The collapsed non-profit entry. The US has no CRA "registered charity"
     * concept, so the copy drops the charity wording there.
     *
     * @return array{value: string, label: string, description: string, help: string}
     */
    private function nonProfitChoice(?Country $country): array
    {
        $isUs = $country === Country::UnitedStates;

        return [
            'value' => self::NONPROFIT_CATEGORY,
            'label' => $isUs ? __('Non-profit') : __('Non-profit or charity'),
            'description' => $isUs
                ? __('A club, association, or non-profit organization.')
                : __('A club, association, non-profit, or registered charity.'),
            'help' => $isUs
                ? __('Choose this for any not-for-profit — an unincorporated club or association, or an incorporated non-profit. The next question pins down which, since each has different filing rules.')
                : __('Choose this for any not-for-profit — an unincorporated club or association, an incorporated non-profit, or a registered charity. The next question pins down which, since each has different filing and receipting rules.'),
        ];
    }

    /**
     * Valid top-level category values for server-side validation.
     *
     * @return list<string>
     */
    private function orgCategoryValues(): array
    {
        return array_map(fn (array $o) => $o['value'], $this->orgTypeChoices());
    }

    /**
     * The non-profit tier attributes to persist, or all-null when the chosen
     * organization type isn't a non-profit.
     *
     * @return array{legal_structure: ?string, charity_registration_number: ?string, contribution_method: ?string}
     */
    protected function nonProfitAttributes(): array
    {
        $isNonProfit = (bool) OrganizationType::tryFrom($this->organizationType)?->isNonProfit();

        return [
            'legal_structure' => $isNonProfit ? ($this->legalStructure ?: null) : null,
            'charity_registration_number' => $isNonProfit ? ($this->charityRegistrationNumber ?: null) : null,
            'contribution_method' => $isNonProfit ? ($this->contributionMethod ?: null) : null,
        ];
    }

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'companyName' => ['required', 'string', 'max:255', new CompanyName],
                'country' => ['required', Rule::in(array_map(fn ($c) => $c->value, Country::cases()))],
                'currency' => ['required', 'string', 'size:3'],
                'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
                'fiscalYearStartMonth' => ['required', 'integer', 'between:1,12'],
                'region' => $this->regions !== []
                    ? ['required', Rule::in(array_keys($this->regions))]
                    : ['nullable', 'string', 'max:100'],
            ],
            2 => [
                'orgCategory' => ['required', Rule::in($this->orgCategoryValues())],
                // Derived from the category (and tier for non-profits); validated
                // as defense in depth. Charity isn't offered outside Canada.
                'organizationType' => array_merge(
                    ['required', Rule::enum(OrganizationType::class)],
                    $this->countryEnum() === Country::UnitedStates
                        ? [Rule::notIn([OrganizationType::Charity->value])]
                        : [],
                ),
                'legalStructure' => array_merge(
                    [
                        Rule::requiredIf(fn () => $this->orgCategory === self::NONPROFIT_CATEGORY),
                        'nullable',
                        Rule::enum(LegalStructure::class),
                    ],
                    // Registered charity isn't offered outside Canada.
                    $this->countryEnum() === Country::UnitedStates
                        ? [Rule::notIn([LegalStructure::RegisteredCharity->value])]
                        : [],
                ),
                'contributionMethod' => [
                    Rule::requiredIf(fn () => $this->orgCategory === self::NONPROFIT_CATEGORY),
                    'nullable',
                    Rule::enum(ContributionMethod::class),
                ],
                'charityRegistrationNumber' => ['nullable', 'string', 'max:32', 'regex:/^\d{9}RR\d{4}$/'],
            ],
            3 => [
                'industry' => ['required', Rule::enum(Industry::class)],
                'chartMode' => ['required', 'in:standard,minimal,copy'],
                'sourceCompanyId' => [
                    Rule::requiredIf($this->chartMode === 'copy'),
                    'nullable',
                    Rule::in(Auth::user()->companies()->pluck('companies.id')->all()),
                ],
            ],
            5 => [
                'taxNumber' => ['nullable', 'string', 'max:50'],
                'pstNumber' => ['nullable', 'string', 'max:50'],
            ],
            6 => array_merge(
                ['startMode' => ['required', 'in:fresh,import,restore-backup']],
                match ($this->startMode) {
                    'import' => ['importMode' => ['required', 'in:opening_balance,full_history']],
                    'restore-backup' => [],
                    default => ['startDate' => ['nullable', 'date']],
                },
            ),
            default => [],
        };
    }

    /**
     * Seed the checkbox state from the current preview, preserving prior user
     * toggles for codes that still exist and forcing locked accounts on.
     */
    protected function syncSelectedAccounts(): void
    {
        $next = [];

        foreach ($this->chartPreview as $row) {
            $code = $row['code'];

            if ($row['locked']) {
                $next[$code] = true;

                continue;
            }

            $next[$code] = array_key_exists($code, $this->selectedAccounts)
                ? (bool) $this->selectedAccounts[$code]
                : $row['default_selected'];
        }

        $this->selectedAccounts = $next;
    }

    /**
     * @return list<string>
     */
    protected function selectedCodes(): array
    {
        $locked = [];
        foreach ($this->chartPreview as $row) {
            if ($row['locked']) {
                $locked[] = $row['code'];
            }
        }

        // PHP casts numeric-string array keys to integers, so normalise back to
        // strings to match the string account codes in the chart preview.
        $chosen = array_map('strval', array_keys(array_filter($this->selectedAccounts, fn ($v) => $v === true)));

        return array_values(array_unique([...$locked, ...$chosen]));
    }

    public function createCompany(CreateCompany $createCompany): void
    {
        // Defense in depth: re-validate every data-bearing step server-side.
        foreach ([1, 2, 3, 5, 6] as $s) {
            $rules = $this->rulesForStep($s);

            if ($rules !== []) {
                $this->validate($rules);
            }
        }

        $country = $this->countryEnum() ?? Country::Canada;
        $this->syncSelectedAccounts();
        $rows = ChartTemplateBuilder::toSeedRows($this->chartPreview, $this->selectedCodes());

        $company = $createCompany->handle(
            user: Auth::user(),
            name: $this->companyName,
            isPersonal: false,
            country: $country,
            regionCode: $this->region ?: null,
            currencyCode: mb_strtoupper($this->currency),
            timezone: $this->timezone ?: null,
            attributes: [
                'fiscal_year_start_month' => $this->fiscalYearStartMonth,
                'industry' => $this->industry,
                'organization_type' => $this->organizationType,
                ...$this->nonProfitAttributes(),
                'tax_number' => $this->taxNumber ?: null,
                'features_inventory' => $this->featuresInventory,
                'features_employees' => $this->featuresEmployees,
                'features_payroll' => $this->featuresPayroll && $this->countryEnum() === Country::Canada,
                'features_fixed_assets' => $this->featuresFixedAssets,
                'features_estimates' => $this->featuresEstimates,
                'features_sales_orders' => $this->featuresSalesOrders,
                'features_recurring_invoices' => $this->featuresRecurringInvoices,
                'features_recurring_bills' => $this->featuresRecurringBills,
                'features_classes' => $this->featuresClasses,
                'features_locations' => $this->featuresLocations,
                'features_budgets' => $this->featuresBudgets,
                'features_membership' => $this->featuresMembership,
                'features_fundraising' => $this->featuresFundraising,
                'setup_completed_at' => now(),
                'settings' => [
                    'setup' => [
                        'start_date' => $this->startDate ?: null,
                        'charges_tax' => $this->chargesTax,
                    ],
                ],
            ],
            pendingChartAccounts: $rows,
        );

        // Guarantee the system payroll accounts exist when payroll is enabled,
        // regardless of which chart template the owner chose.
        if ($company->usesPayroll()) {
            app(\App\Actions\Payroll\EnsurePayrollAccounts::class)->handle($company);
        }

        // Likewise the Inventory Asset + COGS accounts when inventory is enabled
        // (the gated chart drops them otherwise), wiring the company defaults
        // posting resolves through.
        if ($company->usesInventory()) {
            app(\App\Actions\Inventory\EnsureInventoryAccounts::class)->handle($company);
        }

        $this->applyTaxRegistrationNumbers($company);

        Flux::toast(variant: 'success', text: __('Company created.'));

        $this->redirectRoute('dashboard', ['company' => $company->slug], navigate: true);
    }

    /**
     * Import branch: create the company with only the minimal system chart (the
     * import brings its own operating accounts), seed a migration run in the
     * chosen mode, and flip the wizard into the embedded import phase. The
     * industry/chart chosen in step 3 is recorded as metadata but intentionally
     * NOT generated here — QuickBooks supplies the operating accounts.
     */
    public function beginImport(CreateCompany $createCompany): void
    {
        foreach ([1, 2, 3, 5, 6] as $s) {
            $rules = $this->rulesForStep($s);

            if ($rules !== []) {
                $this->validate($rules);
            }
        }

        $country = $this->countryEnum() ?? Country::Canada;

        // No feature gating here: the import replays QuickBooks transactions that
        // may post to inventory/COGS/tax, so the full minimal system core must be
        // present (and its company default columns wired) regardless of which
        // wizard feature toggles were set.
        $minimalPreview = app(ChartTemplateBuilder::class)->build(
            jurisdiction: $country,
            industry: Industry::tryFrom($this->industry) ?? Industry::General,
            orgType: OrganizationType::tryFrom($this->organizationType) ?? OrganizationType::Other,
            minimal: true,
        );
        $rows = ChartTemplateBuilder::toSeedRows($minimalPreview, array_column($minimalPreview, 'code'));

        $company = $createCompany->handle(
            user: Auth::user(),
            name: $this->companyName,
            isPersonal: false,
            country: $country,
            regionCode: $this->region ?: null,
            currencyCode: mb_strtoupper($this->currency),
            timezone: $this->timezone ?: null,
            attributes: [
                'fiscal_year_start_month' => $this->fiscalYearStartMonth,
                'industry' => $this->industry,
                'organization_type' => $this->organizationType,
                ...$this->nonProfitAttributes(),
                'tax_number' => $this->taxNumber ?: null,
                'features_inventory' => $this->featuresInventory,
                'features_employees' => $this->featuresEmployees,
                'features_payroll' => $this->featuresPayroll && $this->countryEnum() === Country::Canada,
                'features_fixed_assets' => $this->featuresFixedAssets,
                'features_estimates' => $this->featuresEstimates,
                'features_sales_orders' => $this->featuresSalesOrders,
                'features_recurring_invoices' => $this->featuresRecurringInvoices,
                'features_recurring_bills' => $this->featuresRecurringBills,
                'features_classes' => $this->featuresClasses,
                'features_locations' => $this->featuresLocations,
                'features_budgets' => $this->featuresBudgets,
                'features_membership' => $this->featuresMembership,
                'features_fundraising' => $this->featuresFundraising,
                'setup_completed_at' => now(),
                'settings' => [
                    'setup' => [
                        'start_date' => $this->startDate ?: null,
                        'charges_tax' => $this->chargesTax,
                        'import_mode' => $this->importMode,
                    ],
                ],
            ],
            pendingChartAccounts: $rows,
        );

        if ($company->usesPayroll()) {
            app(\App\Actions\Payroll\EnsurePayrollAccounts::class)->handle($company);
        }

        $this->applyTaxRegistrationNumbers($company);

        $modeEnum = $this->importMode === DataMigrationMode::FullHistory->value
            ? DataMigrationMode::FullHistory
            : DataMigrationMode::OpeningBalance;

        app(QuickBooksMigrationService::class)->startOrResume($company, mode: $modeEnum);

        // Leave the run on its first step (setup) so the embedded import opens on
        // the conversion-date picker. startOrResume seeds a sensible default
        // conversion date (the most recent fiscal year-end) that the user confirms
        // or changes there — we deliberately do NOT prefill it from the wizard's
        // "today" default, which is the company's go-live date, not the books'.
        $this->createdCompanyId = $company->id;
    }
}; ?>

<section class="w-full">
    @if ($this->createdCompanyId)
        <div class="mb-6">
            <flux:heading size="xl" level="1">{{ __('Import from QuickBooks') }}</flux:heading>
            <flux:text>{{ __('Your company is ready. Bring in your QuickBooks data below, or choose “Finish later” to do it from the dashboard anytime.') }}</flux:text>
        </div>

        @livewire('pages::migration.import', ['company' => $this->company, 'embedded' => true, 'presetMode' => $importMode], 'qb-import-'.$this->createdCompanyId)
    @else
        <div class="mb-6">
            <flux:heading size="xl" level="1">{{ __('Setup your organization') }}</flux:heading>
            <flux:text>{{ __('A few quick questions tailor your chart of accounts, tax codes, and terminology. You can change everything later.') }}</flux:text>
        </div>

        <div
            class="flex gap-6 max-md:flex-col"
            x-data
            x-init="$wire.detectTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone || '')"
        >
        <aside class="w-64 shrink-0">
            <ol class="rounded-lg border border-border bg-card p-3">
                @foreach ($this->steps as $num => $stepMeta)
                    @php
                        $isCurrent = $this->step === $num;
                        $isDone = $this->step > $num;
                    @endphp
                    <li>
                        <button
                            type="button"
                            wire:click="jumpTo({{ $num }})"
                            @disabled($num >= $this->step)
                            class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm transition
                                {{ $isCurrent ? 'bg-muted font-semibold' : 'hover:bg-muted' }}
                                {{ $num > $this->step ? 'cursor-default opacity-60' : '' }}"
                        >
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs
                                {{ $isDone ? 'border-emerald-600 bg-emerald-600 text-white' : ($isCurrent ? 'border-foreground' : 'border-border') }}">
                                {{ $isDone ? '✓' : $num }}
                            </span>
                            <span>{{ $stepMeta['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </aside>

        <main class="flex-1">
            <div class="rounded-lg border border-border bg-card p-6">
                {{-- Step 1 — Organization info --}}
                @if ($this->step === 1)
                    <flux:heading size="lg" class="mb-4">{{ __('Organization info') }}</flux:heading>
                    <div class="space-y-5">
                        <flux:field>
                            <flux:label class="flex items-center gap-1.5">
                                {{ __('Organization name') }}
                                <flux:tooltip toggleable>
                                    <flux:button icon="question-mark-circle" size="sm" variant="ghost" />
                                    <flux:tooltip.content class="max-w-[20rem]">
                                        {{ __('The legal or trading name your customers will recognize — it appears on invoices, statements, and emails. You can adjust your display name and branding later in settings.') }}
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </flux:label>
                            <flux:input wire:model="companyName" autofocus data-test="wizard-company-name" />
                            <flux:error name="companyName" />
                        </flux:field>

                        <flux:select wire:model.live="country" :label="__('Country')" data-test="wizard-country">
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            @foreach (Country::options() as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>{{ __("Your country can't be changed after creation.") }}</flux:description>

                        @if ($this->regions !== [])
                            <flux:select wire:model="region" :label="$this->countryEnum()?->regionLabel() ?? __('Region')" data-test="wizard-region">
                                <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                                @foreach ($this->regions as $code => $name)
                                    <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:input wire:model="region" :label="$this->countryEnum()?->regionLabel() ?? __('Region')" data-test="wizard-region" />
                        @endif

                        <flux:select wire:model="currency" :label="__('Base currency')" data-test="wizard-currency">
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            <flux:select.option value="CAD">CAD &mdash; Canadian Dollar</flux:select.option>
                            <flux:select.option value="USD">USD &mdash; United States Dollar</flux:select.option>
                        </flux:select>

                        <flux:select wire:model="timezone" :label="__('Timezone')" data-test="wizard-timezone">
                            @foreach (\App\Models\Company::timezoneOptions() as $tzLabel => $tzId)
                                <flux:select.option value="{{ $tzId }}">{{ $tzLabel }}</flux:select.option>
                            @endforeach
                            @unless (in_array($timezone, \App\Models\Company::timezoneOptions(), true))
                                <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                            @endunless
                        </flux:select>
                        <flux:description>{{ __('Transaction dates default to today in this timezone. You can change it later in settings.') }}</flux:description>

                        <flux:field>
                            <flux:label class="flex items-center gap-1.5">
                                {{ __('Fiscal year start month') }}
                                <flux:tooltip toggleable>
                                    <flux:button icon="question-mark-circle" size="sm" variant="ghost" />
                                    <flux:tooltip.content class="max-w-[20rem]">
                                        {{ __('The month your accounting year begins. Most businesses start in January, but choose the month your fiscal year opens if it differs — it sets how reports group transactions into financial years.') }}
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </flux:label>
                            <flux:select wire:model.live="fiscalYearStartMonth" data-test="wizard-fiscal-month">
                                @foreach (range(1, 12) as $monthNumber)
                                    <flux:select.option value="{{ $monthNumber }}">{{ \Carbon\CarbonImmutable::create(null, $monthNumber, 1)->translatedFormat('F') }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @php
                                $fyEndMonth = (int) $fiscalYearStartMonth === 1 ? 12 : (int) $fiscalYearStartMonth - 1;
                                $fyEndLabel = \Carbon\CarbonImmutable::create(null, $fyEndMonth, 1)->endOfMonth()->translatedFormat('F j');
                            @endphp
                            <flux:description data-test="wizard-fiscal-end">{{ __('Your fiscal year-end is :date.', ['date' => $fyEndLabel]) }}</flux:description>
                            <flux:error name="fiscalYearStartMonth" />
                        </flux:field>
                    </div>
                @endif

                {{-- Step 2 — Organization type --}}
                @if ($this->step === 2)
                    <flux:heading size="lg" class="mb-4">{{ __('How is your organization organized?') }}</flux:heading>
                    <flux:radio.group wire:model.live="orgCategory" class="space-y-4" data-test="wizard-org-type">
                        @foreach ($this->orgTypeChoices as $option)
                            {{-- Radio shrinks to its label (w-fit) so the help icon sits right after the
                                 text, as on step 1. The description renders below, indented to align under
                                 the label (radio 1.125rem + grid gap 0.75rem = 1.875rem). --}}
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <flux:radio value="{{ $option['value'] }}" :label="$option['label']" class="w-fit" />
                                    <flux:tooltip toggleable>
                                        <flux:button icon="question-mark-circle" size="sm" variant="ghost" />
                                        <flux:tooltip.content class="max-w-[20rem]">
                                            {{ $option['help'] }}
                                        </flux:tooltip.content>
                                    </flux:tooltip>
                                </div>
                                <flux:description class="mt-1 ms-[1.875rem]">{{ $option['description'] }}</flux:description>
                            </div>
                        @endforeach
                    </flux:radio.group>

                    @if ($this->nonProfitSelected)
                        <div class="mt-8 space-y-8 border-t border-border pt-8" data-test="wizard-nonprofit">
                            <flux:radio.group
                                wire:model.live="legalStructure"
                                :label="__('What is your legal structure?')"
                                data-test="wizard-legal-structure"
                            >
                                @foreach (LegalStructure::options($this->countryEnum()) as $option)
                                    <flux:radio value="{{ $option['value'] }}" :label="$option['label']" :description="$option['description']" />
                                @endforeach
                            </flux:radio.group>

                            @if (LegalStructure::tryFrom($legalStructure)?->requiresCharityRegistration())
                                <flux:input
                                    wire:model="charityRegistrationNumber"
                                    :label="__('CRA charity registration number')"
                                    placeholder="123456789RR0001"
                                    :description="__('Your Business Number with RR program account. You can add this later in settings to start issuing donation receipts.')"
                                    data-test="wizard-charity-number"
                                />
                            @endif

                            @if (LegalStructure::tryFrom($legalStructure) === LegalStructure::UnincorporatedAssociation)
                                <flux:text class="text-muted-foreground" data-test="wizard-club-note">
                                    {{ __('Your books will be set up as an unincorporated association — membership-dues income, expenses, and net assets — using the deferral method for dues paid in advance. You can change this later in settings.') }}
                                </flux:text>
                            @else
                                <flux:radio.group
                                    wire:model="contributionMethod"
                                    :label="__('How do you account for restricted contributions?')"
                                    :description="__('Most non-profits use the deferral method. You can change this later in settings.')"
                                    data-test="wizard-contribution-method"
                                >
                                    @foreach (ContributionMethod::options() as $option)
                                        <flux:radio value="{{ $option['value'] }}" :label="$option['label']" :description="$option['description']" />
                                    @endforeach
                                </flux:radio.group>
                            @endif
                        </div>
                    @endif
                @endif

                {{-- Step 3 — Industry + chart mode --}}
                @if ($this->step === 3)
                    <flux:heading size="lg" class="mb-4">{{ __('Select your industry') }}</flux:heading>

                    <flux:radio.group wire:model.live="chartMode" variant="segmented" class="mb-6" data-test="wizard-chart-mode">
                        <flux:radio value="standard">{{ __('Standardized accounts') }}</flux:radio>
                        <flux:radio value="copy">{{ __('Copy an existing organization') }}</flux:radio>
                        <flux:radio value="minimal">{{ __("Start minimal — I'll add my own") }}</flux:radio>
                    </flux:radio.group>

                    @if ($this->chartMode === 'standard')
                        <flux:radio.group wire:model="industry" data-test="wizard-industry">
                            @foreach (Industry::options() as $option)
                                <flux:radio value="{{ $option['value'] }}" :label="$option['label']" :description="$option['description']" />
                            @endforeach
                        </flux:radio.group>
                    @elseif ($this->chartMode === 'copy')
                        @if ($this->sourceCompanies->isEmpty())
                            <flux:callout icon="information-circle">
                                <flux:callout.text>
                                    {{ __('You are not a member of any other organization to copy from.') }}
                                </flux:callout.text>
                            </flux:callout>
                        @else
                            <flux:select wire:model.live="sourceCompanyId" :label="__('Copy accounts from')" :placeholder="__('Choose an organization')" data-test="wizard-source-company">
                                @foreach ($this->sourceCompanies as $sourceCompany)
                                    <flux:select.option value="{{ $sourceCompany->id }}">{{ $sourceCompany->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:callout icon="information-circle" class="mt-4">
                                <flux:callout.text>
                                    {{ __("We'll copy that organization's chart of accounts — codes, names, descriptions, GIFI codes, and sub-account nesting. Review and trim it on the next steps.") }}
                                </flux:callout.text>
                            </flux:callout>
                        @endif
                    @else
                        <flux:callout icon="information-circle">
                            <flux:callout.text>
                                {{ __('We will create only the required accounts (bank, receivables, payables, tax, equity) so you can start clean and add the rest yourself.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                @endif

                {{-- Step 4 — Features --}}
                @if ($this->step === 4)
                    <flux:heading size="lg" class="mb-4">{{ __('What do you want to track?') }}</flux:heading>
                    <flux:text class="mb-6">{{ __('Turn on the modules you need. You can change these anytime in company settings.') }}</flux:text>
                    <div class="space-y-6" data-test="wizard-features">
                        {{-- Sales & Income --}}
                        <div>
                            <flux:subheading class="mb-3">{{ __('Sales & Income') }}</flux:subheading>
                            <div class="space-y-4">
                                <flux:switch wire:model="featuresEstimates" :label="__('Estimates')" :description="__('Send customer estimates and convert them to invoices.')" data-test="wizard-features-estimates" />
                                <flux:switch wire:model="featuresSalesOrders" :label="__('Sales orders')" :description="__('Track customer sales orders and fulfil them with invoices.')" data-test="wizard-features-sales-orders" />
                                <flux:switch wire:model="featuresRecurringInvoices" :label="__('Recurring invoices')" :description="__('Schedule recurring customer invoices.')" data-test="wizard-features-recurring-invoices" />
                            </div>
                        </div>

                        {{-- Costs & Expenses. Inventory/Employees/Payroll are hidden when the platform admin disables that section globally; Payroll is additionally Canada-only. --}}
                        <div>
                            <flux:subheading class="mb-3">{{ __('Costs & Expenses') }}</flux:subheading>
                            <div class="space-y-4">
                                @if (SiteSettings::sectionEnabled(Section::Inventory))
                                    <flux:switch wire:model="featuresInventory" :label="__('Inventory')" :description="__('Track stock on hand, costing, and cost of goods sold.')" data-test="wizard-features-inventory" />
                                @endif
                                @if (SiteSettings::sectionEnabled(Section::Employees))
                                    <flux:switch wire:model="featuresEmployees" :label="__('Employees')" :description="__('Track employees and reimbursements.')" data-test="wizard-features-employees" />
                                @endif
                                @if ($this->countryEnum() === Country::Canada && SiteSettings::sectionEnabled(Section::Payroll))
                                    <flux:switch wire:model="featuresPayroll" :label="__('Payroll')" :description="__('Run Canadian payroll: pay employees, calculate CPP/EI/income tax, write cheques and prepare PD7A/T4/ROE.')" data-test="wizard-features-payroll" />
                                @endif
                                <flux:switch wire:model="featuresFixedAssets" :label="__('Fixed assets')" :description="__('Track capital assets and depreciation.')" data-test="wizard-features-fixed-assets" />
                                <flux:switch wire:model="featuresRecurringBills" :label="__('Recurring bills')" :description="__('Schedule recurring vendor bills.')" data-test="wizard-features-recurring-bills" />
                            </div>
                        </div>

                        {{-- Non-Profit & Associations. Donations & grants is hidden when the Fundraising section is disabled globally. --}}
                        <div>
                            <flux:subheading class="mb-3">{{ __('Non-Profit & Associations') }}</flux:subheading>
                            <div class="space-y-4">
                                <flux:switch wire:model="featuresMembership" :label="__('Membership')" :description="__('Track members and membership levels, and bill recurring dues as invoices.')" data-test="wizard-features-membership" />
                                @if (SiteSettings::sectionEnabled(Section::Fundraising))
                                    <flux:switch wire:model="featuresFundraising" :label="__('Donations & grants')" :description="__('Record donation and grant income, track restricted funding, and issue donation receipts.')" data-test="wizard-features-fundraising" />
                                @endif
                            </div>
                        </div>

                        {{-- Planning & Accounting --}}
                        <div>
                            <flux:subheading class="mb-3">{{ __('Planning & Accounting') }}</flux:subheading>
                            <div class="space-y-4">
                                <flux:switch wire:model="featuresBudgets" :label="__('Budgets')" :description="__('Plan account-level budgets and compare them against actuals.')" data-test="wizard-features-budgets" />
                                <flux:switch wire:model="featuresLocations" :label="__('Locations')" :description="__('Tag transactions with a location to slice reports by site, branch, or property.')" data-test="wizard-features-locations" />
                                <flux:switch wire:model="featuresClasses" :label="__('Classes')" :description="__('Tag transactions with a class to slice reports by segment, department, or program.')" data-test="wizard-features-classes" />
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Step 5 — Sales tax --}}
                @if ($this->step === 5)
                    <flux:heading size="lg" class="mb-4">{{ __('Do you charge sales tax?') }}</flux:heading>
                    <div class="space-y-5">
                        <flux:switch wire:model.live="chargesTax" :label="__('I charge :tax', ['tax' => $this->countryEnum()?->taxLabel() ?? __('sales tax')])" data-test="wizard-charges-tax" />

                        @if ($this->provincialSalesTax())
                            <flux:switch wire:model.live="chargesPst" :label="__('I charge :tax', ['tax' => $this->provincialSalesTax()->taxLabel()])" :description="__('We will add a :tax payable account and tax code for :province.', ['tax' => $this->provincialSalesTax()->taxLabel(), 'province' => $this->regions[$this->region] ?? $this->region])" data-test="wizard-charges-pst" />
                        @endif

                        @if ($this->chargesTax)
                            <flux:input wire:model="taxNumber" :label="__(':tax account number', ['tax' => $this->countryEnum()?->taxLabel() ?? __('Sales tax')])" :description="__('Optional. Shown on invoices when enabled in settings.')" data-test="wizard-tax-number" />
                        @endif

                        @if ($this->provincialSalesTax() && $this->chargesPst)
                            <flux:input wire:model="pstNumber" :label="__(':tax account number', ['tax' => $this->provincialSalesTax()->taxLabel()])" :description="__('Optional. Your :tax registration with :province.', ['tax' => $this->provincialSalesTax()->taxLabel(), 'province' => $this->regions[$this->region] ?? $this->region])" data-test="wizard-pst-number" />
                        @endif
                    </div>
                @endif

                {{-- Step 6 — How to start --}}
                @if ($this->step === 6)
                    <flux:heading size="lg" class="mb-4">{{ __('How do you want to start?') }}</flux:heading>

                    <flux:radio.group wire:model.live="startMode" class="mb-4" data-test="wizard-start-mode">
                        <flux:radio value="fresh" :label="__('Start fresh')" :description="__('Begin from a start date with the chart of accounts you just chose.')" />
                        <flux:radio value="import" :label="__('Import from QuickBooks')" :description="__('Bring your existing QuickBooks data in. We seed only the required accounts, then import the rest.')" />
                        <flux:radio value="restore-backup" :label="__('Restore from a backup')" :description="__('Upload a backup ZIP from another LineLedger instance to rebuild a company here.')" />
                    </flux:radio.group>

                    @if ($this->startMode === 'fresh')
                        <flux:input type="date" wire:model="startDate" :label="__('Start date')" :description="__('Usually the beginning of your fiscal year or today.')" data-test="wizard-start-date" />
                    @elseif ($this->startMode === 'import')
                        <flux:radio.group wire:model.live="importMode" :label="__('What do you want to import?')" data-test="wizard-import-mode">
                            <flux:radio value="opening_balance" :label="__('Opening balances / trial balance')" :description="__('Lists and balances as of a conversion date, then lock the books.')" />
                            <flux:radio value="full_history" :label="__('Full transaction history')" :description="__('Replay every QuickBooks transaction into the general ledger.')" />
                        </flux:radio.group>
                    @else
                        <flux:callout icon="information-circle">
                            <flux:callout.text>
                                {{ __('Continue to upload your backup ZIP. You will pick the file on the next screen.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                @endif

                {{-- Step 7 — Review chart of accounts --}}
                @if ($this->step === 7)
                    <flux:heading size="lg" class="mb-2">{{ __('Review your chart of accounts') }}</flux:heading>
                    <flux:text class="mb-4">{{ __('Uncheck any accounts you do not need. Required system accounts are locked on. You can edit, add, or deactivate accounts later.') }}</flux:text>

                    @php
                        $grouped = collect($this->chartPreview)->groupBy(fn ($row) => $row['subtype']->type()->label());
                        $order = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
                    @endphp

                    <div class="space-y-5" data-test="wizard-chart-preview">
                        @foreach ($order as $typeLabel)
                            @if ($grouped->has($typeLabel))
                                <div>
                                    <flux:subheading class="mb-2">{{ $typeLabel === 'Equity' ? $this->equitySectionLabel() : __($typeLabel) }}</flux:subheading>
                                    <div class="divide-y divide-border rounded-lg border border-border">
                                        @foreach ($grouped->get($typeLabel) as $row)
                                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                                <flux:checkbox
                                                    wire:model="selectedAccounts.{{ $row['code'] }}"
                                                    :label="$row['code'].'  '.$row['name']"
                                                    :disabled="$row['locked']"
                                                />
                                                @if ($row['is_system'])
                                                    <flux:badge size="sm" color="zinc">{{ __('System') }}</flux:badge>
                                                @elseif ($row['locked'])
                                                    <flux:badge size="sm" color="zinc">{{ __('Required') }}</flux:badge>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Step 8 — Confirm --}}
                @if ($this->step === 8)
                    <flux:heading size="lg" class="mb-4">{{ __('Ready to create your company') }}</flux:heading>
                    @php $selectedCount = count(array_filter($this->selectedAccounts, fn ($v) => $v === true)); @endphp
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between border-b border-border pb-2">
                            <dt class="text-muted-foreground">{{ __('Company') }}</dt>
                            <dd class="font-medium">{{ $companyName ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-border pb-2">
                            <dt class="text-muted-foreground">{{ __('Jurisdiction') }}</dt>
                            <dd class="font-medium">{{ $this->countryEnum()?->label() }} · {{ $currency }}</dd>
                        </div>
                        <div class="flex justify-between border-b border-border pb-2">
                            <dt class="text-muted-foreground">{{ __('Organization') }}</dt>
                            <dd class="font-medium">{{ \App\Enums\OrganizationType::tryFrom($organizationType)?->label() }}</dd>
                        </div>
                        @if (\App\Enums\OrganizationType::tryFrom($organizationType)?->isNonProfit())
                            <div class="flex justify-between border-b border-border pb-2">
                                <dt class="text-muted-foreground">{{ __('Legal structure') }}</dt>
                                <dd class="font-medium">{{ \App\Enums\LegalStructure::tryFrom($legalStructure)?->label() ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-border pb-2">
                                <dt class="text-muted-foreground">{{ __('Contribution method') }}</dt>
                                <dd class="font-medium">{{ \App\Enums\ContributionMethod::tryFrom($contributionMethod)?->label() ?? '—' }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between border-b border-border pb-2">
                            <dt class="text-muted-foreground">{{ __('Chart of accounts') }}</dt>
                            <dd class="font-medium">
                                {{ $chartMode === 'minimal'
                                    ? __('Minimal')
                                    : ($chartMode === 'copy'
                                        ? __('Copied from :name', ['name' => $this->sourceCompany()?->name ?? '—'])
                                        : \App\Enums\Industry::tryFrom($industry)?->label()) }}
                                · {{ trans_choice(':count account|:count accounts', $selectedCount, ['count' => $selectedCount]) }}
                            </dd>
                        </div>
                    </dl>
                @endif

                {{-- Navigation --}}
                <div class="mt-6 flex items-center justify-between gap-2">
                    <flux:button variant="ghost" wire:click="back" :disabled="$this->step === 1">{{ __('Back') }}</flux:button>

                    @if ($this->step === 6 && $this->startMode === 'import')
                        <flux:button variant="primary" wire:click="beginImport" data-test="wizard-begin-import">{{ __('Begin import') }}</flux:button>
                    @elseif ($this->step === 6 && $this->startMode === 'restore-backup')
                        <flux:button variant="primary" wire:click="next" data-test="wizard-go-to-restore">{{ __('Upload backup') }}</flux:button>
                    @elseif ($this->step < $this->maxStep)
                        <flux:button variant="primary" wire:click="next" data-test="wizard-next">{{ __('Continue') }}</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="createCompany" data-test="wizard-create">{{ __('Create organization') }}</flux:button>
                    @endif
                </div>
            </div>
        </main>
        </div>
    @endif
</section>
