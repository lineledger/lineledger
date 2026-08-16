<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueCompanySlugs;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\CostingMethod;
use App\Enums\Country;
use App\Enums\Industry;
use App\Enums\JurisdictionCapability;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\RemittanceFrequency;
use App\Enums\Section;
use App\Support\Jurisdiction\JurisdictionProfile;
use App\Support\SiteSettings;
use App\Support\Storage\StorageDisks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CompanyFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Larastan cannot infer casts() on a model this large, so the two attributes
 * whose mis-inference (string) breaks call sites are annotated explicitly.
 *
 * @property OrganizationType|null $organization_type
 * @property array<string, mixed>|null $settings
 * @property string|null $inbound_email_token
 * @property bool $inbound_email_enabled
 */
#[Fillable([
    'name',
    'slug',
    'is_personal',
    'industry',
    'organization_type',
    'legal_structure',
    'charity_registration_number',
    'contribution_method',
    'setup_completed_at',
    'legal_name',
    'currency_code',
    'multicurrency_enabled',
    'exchange_gain_loss_account_id',
    'unrealized_gain_loss_account_id',
    'fiscal_year_start_month',
    'timezone',
    'auto_apply_customer_credits',
    'warn_duplicate_bill_no',
    'cheque_offset_x',
    'cheque_offset_y',
    'require_two_factor',
    'costing_method',
    'default_inventory_asset_account_id',
    'default_cogs_account_id',
    'lock_date',
    'tax_number',
    'phone',
    'website',
    'email',
    'address_line1',
    'address_line2',
    'address_city',
    'address_region',
    'address_postal_code',
    'address_country',
    'settings',
    'logo_path',
    'document_logo_path',
    'document_logo_max_height',
    'brand_name',
    'brand_initials',
    'brand_text_color',
    'brand_background_color',
    'features_employees',
    'features_payroll',
    'qhsf_rate_bp',
    'cnesst_rate_bp',
    'wsdrf_applicable',
    'payroll_standard_annual_hours',
    'payroll_overtime_weekly_threshold_hours',
    'payroll_banked_overtime_liability',
    'portal_team_calendar',
    'payroll_business_number',
    'payroll_rp_account',
    'payroll_contact_name',
    'payroll_contact_email',
    'payroll_contact_phone',
    'payroll_work_location',
    'payroll_federally_regulated',
    'payroll_remittance_frequency',
    'features_inventory',
    'features_fixed_assets',
    'features_estimates',
    'features_sales_orders',
    'features_purchase_orders',
    'features_recurring_invoices',
    'features_recurring_bills',
    'features_classes',
    'features_locations',
    'features_funds',
    'features_budgets',
    'features_membership',
    'features_fundraising',
    'stripe_account_id',
    'stripe_connected_at',
    'inbound_email_token',
    'inbound_email_enabled',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use GeneratesUniqueCompanySlugs, HasFactory, SoftDeletes;

    /**
     * Transient hand-off from the setup wizard: the exact chart-of-accounts rows
     * the user selected. Read by CompanyObserver::created() when present (else it
     * falls back to the jurisdiction's full default chart). Never persisted — it
     * is not a column, fillable, or cast.
     *
     * @var list<array{code: string, name: string, subtype: AccountSubtype, is_system?: bool, description?: string, gifi_code?: string, parent_code?: string}>|null
     */
    public ?array $pendingChartAccounts = null;

    /**
     * Memo for {@see documentLogoDataUri()}, plus the logo path it was computed
     * from. `false` on the key means "not yet resolved" — null is a legitimate
     * path (no logo set), so it can't double as the sentinel.
     */
    private string|null|false $documentLogoMemoKey = false;

    private ?string $documentLogoDataUriMemo = null;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Company $company) {
            if (empty($company->slug)) {
                $company->slug = static::generateUniqueCompanySlug($company->name);
            }

            $company->address_country ??= Country::Canada->value;
            $country = Country::tryFrom($company->address_country) ?? Country::Canada;
            $company->currency_code ??= $country->defaultCurrencyCode();
            $company->fiscal_year_start_month ??= 1;

            if (empty($company->timezone)) {
                $company->timezone = $country->defaultTimezone($company->address_region);
            }
        });

        static::updating(function (Company $company) {
            if ($company->isDirty('name')) {
                $company->slug = static::generateUniqueCompanySlug($company->name, $company->id);
            }

            if ($company->isDirty('address_country')
                && $company->getOriginal('address_country') !== null) {
                throw new DomainException('Company country cannot be changed after creation.');
            }
        });
    }

    public function owner(): ?User
    {
        return $this->members()
            ->wherePivot('role', CompanyRole::Owner->value)
            ->first();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_members', 'company_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<CompanyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<PayrollRemittance, $this>
     */
    public function payrollRemittances(): HasMany
    {
        return $this->hasMany(PayrollRemittance::class);
    }

    /**
     * @return HasMany<WorkersCompSetting, $this>
     */
    public function workersCompSettings(): HasMany
    {
        return $this->hasMany(WorkersCompSetting::class);
    }

    /**
     * @return HasMany<CompanyCurrency, $this>
     */
    public function currencies(): HasMany
    {
        return $this->hasMany(CompanyCurrency::class);
    }

    /**
     * Whether this currency code is the company's home (functional) currency.
     * Treats a null/empty code as home, so single-currency call paths that omit
     * a currency always resolve to the home currency.
     */
    public function isHomeCurrency(?string $code): bool
    {
        return $code === null || $code === '' || mb_strtoupper($code) === mb_strtoupper((string) $this->currency_code);
    }

    public function isMulticurrencyEnabled(): bool
    {
        return (bool) $this->multicurrency_enabled;
    }

    /**
     * @return HasMany<CompanyApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(CompanyApiKey::class);
    }

    /**
     * @return HasOne<InvoiceSetting, $this>
     */
    public function invoiceSettings(): HasOne
    {
        return $this->hasOne(InvoiceSetting::class);
    }

    /**
     * The persisted invoice template settings, or a fresh unsaved instance
     * hydrated with defaults so callers (e.g. the print PDF) always have a
     * settings object even before the user visits the settings page.
     */
    public function invoiceSettingsOrNew(): InvoiceSetting
    {
        return $this->invoiceSettings
            ?? new InvoiceSetting(['company_id' => $this->id, ...InvoiceSetting::defaults()]);
    }

    /**
     * Determine if posting on the given date is blocked by the company lock date.
     */
    public function isLockedFor(CarbonInterface $date): bool
    {
        return $this->lock_date !== null
            && $date->lessThanOrEqualTo($this->lock_date);
    }

    /**
     * The current date and time in this company's timezone. The single source
     * for "now"/"today" when defaulting transaction dates and report ranges, so
     * a late-evening entry posts on the local calendar day rather than the UTC
     * one. Callers chain ->toDateString(), ->addDays(), ->startOfMonth(), etc.
     */
    public function currentDateTime(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone ?: 'UTC');
    }

    /**
     * Curated timezone choices for the company settings picker, label => IANA
     * identifier, ordered roughly west-to-east by UTC offset. A popular subset
     * covering the major business regions worldwide — any valid IANA id is
     * still accepted on save for anything not listed here.
     *
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        return [
            // Americas
            'Hawaii' => 'Pacific/Honolulu',
            'Alaska' => 'America/Anchorage',
            'Pacific Time (US & Canada)' => 'America/Los_Angeles',
            'Mountain Time (US & Canada)' => 'America/Denver',
            'Arizona (no DST)' => 'America/Phoenix',
            'Central Time (US & Canada)' => 'America/Chicago',
            'Mexico City' => 'America/Mexico_City',
            'Eastern Time (US & Canada)' => 'America/New_York',
            'Atlantic Time (Canada)' => 'America/Halifax',
            'Newfoundland (Canada)' => 'America/St_Johns',
            'São Paulo' => 'America/Sao_Paulo',
            'Buenos Aires' => 'America/Argentina/Buenos_Aires',

            // UTC
            'UTC' => 'UTC',

            // Europe & Africa
            'London, Dublin, Lisbon' => 'Europe/London',
            'Central European (Paris, Berlin, Madrid, Rome)' => 'Europe/Paris',
            'Eastern European (Athens, Helsinki)' => 'Europe/Athens',
            'Lagos, West Africa' => 'Africa/Lagos',
            'Cairo' => 'Africa/Cairo',
            'Johannesburg' => 'Africa/Johannesburg',
            'Moscow' => 'Europe/Moscow',

            // Middle East & Asia
            'Dubai' => 'Asia/Dubai',
            'Karachi' => 'Asia/Karachi',
            'India (Mumbai, Delhi, Kolkata)' => 'Asia/Kolkata',
            'Dhaka' => 'Asia/Dhaka',
            'Bangkok, Jakarta' => 'Asia/Bangkok',
            'Singapore' => 'Asia/Singapore',
            'Hong Kong' => 'Asia/Hong_Kong',
            'China (Beijing, Shanghai)' => 'Asia/Shanghai',
            'Tokyo' => 'Asia/Tokyo',
            'Seoul' => 'Asia/Seoul',

            // Oceania
            'Perth' => 'Australia/Perth',
            'Sydney, Melbourne' => 'Australia/Sydney',
            'Auckland' => 'Pacific/Auckland',
        ];
    }

    /**
     * The jurisdiction this company operates under. Drives terminology
     * (cheque/check, postal/zip, province/state) and the seeded defaults.
     * Falls back to Canada for legacy rows missing an address_country.
     */
    public function getJurisdictionAttribute(): Country
    {
        return Country::tryFrom((string) $this->address_country) ?? Country::Canada;
    }

    /**
     * Whether this company may use the given jurisdiction-locked capability. The
     * single entry point every jurisdiction guard reads from; resolution (and the
     * Canada/US matrix) lives in {@see JurisdictionProfile}.
     */
    public function supports(JurisdictionCapability $capability): bool
    {
        return JurisdictionProfile::for($this)->supports($capability);
    }

    /**
     * The company's legal tier, falling back to the default implied by its
     * organization type when none was explicitly chosen (a NonProfit defaults to
     * a non-profit corporation, a Club to an unincorporated association, etc.).
     */
    public function resolvedLegalStructure(): ?LegalStructure
    {
        return $this->legal_structure
            ?? ($this->organization_type !== null ? LegalStructure::fromOrganizationType($this->organization_type) : null);
    }

    /**
     * Whether the GIFI Statement applies — i.e. the company files a T2 (a
     * for-profit corporation or a non-profit corporation). Partnerships file the
     * T5013 and sole proprietors the T2125; both use GIFI lines but have their own
     * reports. See {@see JurisdictionProfile}.
     */
    public function usesGifi(): bool
    {
        return $this->supports(JurisdictionCapability::GifiStatement);
    }

    /** Whether the company files the T5013 partnership return. */
    public function filesT5013(): bool
    {
        return $this->supports(JurisdictionCapability::T5013);
    }

    /** Whether the company files the T2125 statement of business activities. */
    public function filesT2125(): bool
    {
        return $this->supports(JurisdictionCapability::T2125);
    }

    /**
     * Whether per-account GIFI line mapping is useful for this company (it files a
     * T2, T5013, or T2125 — all built on GIFI line codes).
     */
    public function mapsGifiCodes(): bool
    {
        return $this->supports(JurisdictionCapability::GifiCodeMapping);
    }

    /**
     * Whether the Canadian payroll module is available. Payroll is Canada-only
     * (CPP/EI/T4127) and opt-in per company via the features_payroll flag, or
     * granted by the site admin via the payroll_admin_enabled_at override.
     */
    public function usesPayroll(): bool
    {
        return $this->supports(JurisdictionCapability::Payroll);
    }

    /**
     * Whether a sidebar/route section is reachable for this specific company.
     * Layers the platform-wide kill switch ({@see SiteSettings::sectionEnabled})
     * with per-company admin overrides — currently only Payroll has one. When
     * the global Payroll section is off but `payroll_admin_enabled_at` is set,
     * this single tenant still sees Payroll while everyone else does not.
     */
    public function sectionEnabled(Section $section): bool
    {
        if (SiteSettings::sectionEnabled($section)) {
            return true;
        }

        return match ($section) {
            Section::Payroll => $this->payroll_admin_enabled_at !== null,
            default => false,
        };
    }

    /**
     * Whether an optional pay-statement line item is shown, from the per-company
     * `settings['pay_statement']` map. Legislatively required items are forced on
     * by the renderer regardless of this toggle (see PayStatementJurisdiction).
     */
    public function payStatementSetting(string $key, bool $default = true): bool
    {
        return (bool) data_get($this->settings, 'pay_statement.'.$key, $default);
    }

    /**
     * Whether the getting-started tips feature is switched on for this company.
     * Set to true at creation for every new company (see CreateCompany) and
     * re-armed when an owner clicks "Show getting-started tips" in settings.
     * Existing companies created before the feature have no flag and stay off.
     */
    public function onboardingEnabled(): bool
    {
        return (bool) data_get($this->settings, 'onboarding.enabled', false);
    }

    /**
     * The tip keys the company has checked off, from
     * `settings['onboarding']['completed']`.
     *
     * @return list<string>
     */
    public function onboardingCompletedTips(): array
    {
        return array_values((array) data_get($this->settings, 'onboarding.completed', []));
    }

    /**
     * Whether the owner has X-closed the tips box. Re-armed (false) when they
     * click "Show getting-started tips" in settings.
     */
    public function onboardingDismissed(): bool
    {
        return (bool) data_get($this->settings, 'onboarding.dismissed', false);
    }

    /**
     * Persist the company's onboarding state, merging into the existing
     * `settings` JSON without disturbing other keys.
     *
     * @param  array{enabled?: bool, completed?: list<string>, dismissed?: bool}  $state
     */
    public function setOnboardingState(array $state): void
    {
        $settings = $this->settings ?? [];
        $settings['onboarding'] = array_merge($settings['onboarding'] ?? [], $state);

        $this->forceFill(['settings' => $settings])->save();
    }

    /**
     * Whether this company has opted in to AI-phrased daily insights. Default
     * OFF: until an owner flips the switch (and the operator has enabled
     * insights AI with an Anthropic key — see config/insights.php), narration
     * is fully deterministic and nothing leaves the server.
     */
    public function insightsAiNarrationEnabled(): bool
    {
        return (bool) data_get($this->settings, 'insights.ai_narration', false);
    }

    /**
     * Persist the company's daily-insight preferences, merging into the
     * existing `settings` JSON without disturbing other keys.
     *
     * @param  array{ai_narration?: bool}  $state
     */
    public function setInsightsState(array $state): void
    {
        $settings = $this->settings ?? [];
        $settings['insights'] = array_merge($settings['insights'] ?? [], $state);

        $this->forceFill(['settings' => $settings])->save();
    }

    /**
     * Whether this company has opted in to AI receipt OCR on the document inbox.
     * The per-company half of the doubly-opt-in gate (the operator half is
     * config('inbox.ai.enabled') + an Anthropic key — see InboxServiceProvider).
     * Default OFF: until an owner flips it, inbox documents go straight to manual
     * review and nothing is ever sent to Anthropic. Mirrors
     * {@see self::insightsAiNarrationEnabled()}.
     */
    public function inboxOcrEnabled(): bool
    {
        return (bool) data_get($this->settings, 'inbox.ocr_enabled', false);
    }

    /**
     * Persist the company's document-inbox preferences, merging into the existing
     * `settings` JSON without disturbing other keys.
     *
     * @param  array{ocr_enabled?: bool}  $state
     */
    public function setInboxState(array $state): void
    {
        $settings = $this->settings ?? [];
        $settings['inbox'] = array_merge($settings['inbox'] ?? [], $state);

        $this->forceFill(['settings' => $settings])->save();
    }

    /**
     * Whether this company has opted in to agentic (write-enabled) MCP. The
     * per-company half of the doubly-opt-in gate (the operator half is
     * config('mcp.write_enabled') — see ProposesWrites::requireAgenticWritesEnabled()).
     * Default OFF. Mirrors {@see self::insightsAiNarrationEnabled()}.
     */
    public function agenticWritesEnabled(): bool
    {
        return (bool) data_get($this->settings, 'mcp.agentic_writes', false);
    }

    /**
     * Persist the company's MCP preferences, merging into the existing `settings`
     * JSON without disturbing other keys.
     *
     * @param  array{agentic_writes?: bool}  $state
     */
    public function setMcpState(array $state): void
    {
        $settings = $this->settings ?? [];
        $settings['mcp'] = array_merge($settings['mcp'] ?? [], $state);

        $this->forceFill(['settings' => $settings])->save();
    }

    /**
     * Whether this company is a registered Canadian charity — and so can issue
     * official donation receipts and file the T3010. Requires the charity org
     * type, a Canadian jurisdiction, and a CRA registration number on file.
     */
    public function isRegisteredCharity(): bool
    {
        return $this->organization_type === OrganizationType::Charity
            && $this->jurisdiction === Country::Canada
            && filled($this->charity_registration_number);
    }

    /**
     * Whether the company accounts for restricted contributions under the ASNPO
     * deferral method. A null method on a non-profit is treated as deferral (the
     * default), so reports always have a concrete method to present.
     */
    public function usesDeferralMethod(): bool
    {
        return (bool) $this->organization_type?->isNonProfit()
            && $this->contribution_method !== ContributionMethod::RestrictedFund;
    }

    /**
     * Whether the company uses the ASNPO restricted fund method (true fund
     * accounting). The Fund dimension is gated on this.
     */
    public function usesRestrictedFundMethod(): bool
    {
        return $this->contribution_method === ContributionMethod::RestrictedFund;
    }

    /**
     * Whether the Fund dimension is active: the funds feature is enabled and the
     * company uses the restricted fund method. The single gate for fund pickers,
     * filters, and per-fund reporting.
     */
    public function tracksFunds(): bool
    {
        return $this->features_funds && $this->usesRestrictedFundMethod();
    }

    /**
     * Whether the inventory module is active: tracking stock on items, backed by
     * the system Inventory Asset + COGS accounts that inventory postings resolve
     * through. Opt-in per company via the features_inventory flag.
     */
    public function usesInventory(): bool
    {
        return (bool) $this->features_inventory;
    }

    /**
     * Whether a document line can carry a second sales tax. Always true: any
     * company may apply two taxes to a line (e.g. federal GST plus a provincial
     * PST/RST/QST), each computed on the line subtotal independently and remitted
     * to its own agency — they never combine into one rate. Drives the secondary
     * tax picker in document forms and the itemized second tax on printed documents.
     * (Companies in a PST province additionally get that tax auto-seeded, but the
     * second slot is available everywhere.)
     */
    public function usesSecondaryTax(): bool
    {
        return true;
    }

    /**
     * Whether the Membership module is active: tracking members, billing dues as
     * invoices, and membership reporting. Opt-in per company and available to any
     * organization type (clubs, gyms, associations — not just non-profits).
     */
    public function tracksMembership(): bool
    {
        return (bool) $this->features_membership;
    }

    /**
     * Whether the Fundraising module is active: recording donations and grants and
     * their reporting. Opt-in per company and available to any organization type.
     * Note: issuing official CRA donation receipts and filing the T3010 remain
     * gated on {@see self::isRegisteredCharity()}, not on this flag.
     */
    public function tracksFundraising(): bool
    {
        return (bool) $this->features_fundraising;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'multicurrency_enabled' => 'boolean',
            'industry' => Industry::class,
            'organization_type' => OrganizationType::class,
            'legal_structure' => LegalStructure::class,
            'contribution_method' => ContributionMethod::class,
            'setup_completed_at' => 'datetime',
            'lock_date' => 'date:Y-m-d',
            'fiscal_year_start_month' => 'integer',
            'document_logo_max_height' => 'integer',
            'auto_apply_customer_credits' => 'boolean',
            'warn_duplicate_bill_no' => 'boolean',
            'cheque_offset_x' => 'decimal:2',
            'cheque_offset_y' => 'decimal:2',
            'require_two_factor' => 'boolean',
            'costing_method' => CostingMethod::class,
            'settings' => 'array',
            'features_employees' => 'boolean',
            'features_payroll' => 'boolean',
            'qhsf_rate_bp' => 'integer',
            'cnesst_rate_bp' => 'integer',
            'wsdrf_applicable' => 'boolean',
            'payroll_standard_annual_hours' => 'integer',
            'payroll_overtime_weekly_threshold_hours' => 'decimal:2',
            'payroll_banked_overtime_liability' => 'boolean',
            'portal_team_calendar' => 'boolean',
            'payroll_federally_regulated' => 'boolean',
            'payroll_remittance_frequency' => RemittanceFrequency::class,
            'features_inventory' => 'boolean',
            'features_fixed_assets' => 'boolean',
            'features_estimates' => 'boolean',
            'features_sales_orders' => 'boolean',
            'features_purchase_orders' => 'boolean',
            'features_recurring_invoices' => 'boolean',
            'features_recurring_bills' => 'boolean',
            'features_classes' => 'boolean',
            'features_locations' => 'boolean',
            'features_funds' => 'boolean',
            'features_budgets' => 'boolean',
            'features_membership' => 'boolean',
            'features_fundraising' => 'boolean',
            'stripe_connected_at' => 'datetime',
            'stripe_disconnected_at' => 'datetime',
            'payroll_admin_enabled_at' => 'datetime',
            'inbound_email_enabled' => 'boolean',
        ];
    }

    /**
     * Whether this company has a Stripe account linked at all (healthy or broken).
     * Drives the "connected" record in company settings; gate actual card
     * collection on {@see self::canAcceptCardPayments()} instead.
     */
    public function hasStripeConnected(): bool
    {
        return $this->stripe_account_id !== null;
    }

    /**
     * Whether the company can currently take card payments in the customer
     * portal: a Stripe account is linked and its connection has not been flagged
     * broken. A revoked/severed connection trips this to false so the portal
     * degrades gracefully instead of every customer hitting a failed charge.
     */
    public function canAcceptCardPayments(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_disconnected_at === null;
    }

    /**
     * Whether a previously-working Stripe connection has stopped working and the
     * owner needs to reconnect. Drives the reconnect prompt in company settings.
     */
    public function stripeConnectionNeedsAttention(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_disconnected_at !== null;
    }

    /**
     * Flag the linked Stripe connection as broken (its access was revoked or the
     * account is no longer reachable with the platform key). Idempotent: returns
     * true only on the first transition, so the caller alerts the owner once and
     * subsequent failed charges don't re-alert.
     */
    public function markStripeConnectionBroken(): bool
    {
        if ($this->stripe_account_id === null || $this->stripe_disconnected_at !== null) {
            return false;
        }

        $this->forceFill(['stripe_disconnected_at' => now()])->save();

        return true;
    }

    public function canChangeCostingMethod(): bool
    {
        return ! StockMovement::query()->where('company_id', $this->id)->exists();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function brandDisplayName(): string
    {
        return filled($this->brand_name) ? $this->brand_name : $this->name;
    }

    public function brandInitials(): string
    {
        if (filled($this->brand_initials)) {
            return mb_strtoupper($this->brand_initials);
        }

        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(array_filter($words), 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }

    public function brandTextColor(): string
    {
        return $this->brand_text_color ?: '#ffffff';
    }

    public function brandBackgroundColor(): string
    {
        return $this->brand_background_color ?: '#18181b';
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk(StorageDisks::logos())->url($this->logo_path);
    }

    public function documentLogoUrl(): ?string
    {
        $path = $this->document_logo_path ?: $this->logo_path;

        if (! $path) {
            return null;
        }

        return Storage::disk(StorageDisks::logos())->url($path);
    }

    /**
     * The logo used on printed documents (invoices, estimates, …) as a base64
     * data URI for reliable embedding in dompdf, or null when no logo is set.
     * Falls back to the sidebar branding logo so companies that only set that
     * one still get a logo on their documents.
     *
     * This is the *only* way a logo reaches a PDF: dompdf runs with
     * `enable_remote` disabled, so it cannot fetch the image by URL.
     *
     * Memoized because on object storage each call is three network round trips
     * (exists + mimeType + get), and a multi-document report bundle renders the
     * same header many times over. The memo is keyed on the resolved path, so
     * replacing the logo on a live instance recomputes rather than going stale —
     * uploads always land on a fresh randomly-named path.
     */
    public function documentLogoDataUri(): ?string
    {
        $path = $this->document_logo_path ?: $this->logo_path;

        if ($this->documentLogoMemoKey === $path) {
            return $this->documentLogoDataUriMemo;
        }

        $this->documentLogoMemoKey = $path;

        $disk = Storage::disk(StorageDisks::logos());

        if (! $path || ! $disk->exists($path)) {
            return $this->documentLogoDataUriMemo = null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return $this->documentLogoDataUriMemo = 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    public function documentLogoMaxHeight(): int
    {
        return $this->document_logo_max_height ?: 64;
    }
}
