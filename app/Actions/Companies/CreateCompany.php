<?php

namespace App\Actions\Companies;

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\NavPreference;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;
use Illuminate\Support\Facades\DB;

class CreateCompany
{
    /**
     * Sidebar catalog keys (see {@see SidebarNavCatalog})
     * hidden by default for the owner of a new non-profit. They remain available
     * and can be re-shown from Settings → Sidebar.
     *
     * @var list<string>
     */
    private const NONPROFIT_HIDDEN_NAV = [
        'sales.customers',
        'sales.sales_receipts',
        'sales.credit_memos',
        'purchases.purchase_orders',
        'purchases.vendor_credits',
    ];

    /**
     * Create a new company and add the user as owner.
     *
     * Currency defaults from the chosen country's default currency when not
     * passed, and timezone from the country/region when not passed. Country is
     * set once here and cannot be changed afterward.
     *
     * When $pendingChartAccounts is provided (the setup wizard), the observer
     * seeds exactly those accounts instead of the jurisdiction's full default
     * chart. We build the instance and set the transient property before
     * saving so CompanyObserver::created() — which fires on this same instance
     * — can read it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{code: string, name: string, subtype: AccountSubtype, is_system?: bool, description?: string, gifi_code?: string, parent_code?: string}>|null  $pendingChartAccounts
     */
    public function handle(
        User $user,
        string $name,
        bool $isPersonal = false,
        ?Country $country = null,
        ?string $regionCode = null,
        ?string $currencyCode = null,
        ?string $timezone = null,
        array $attributes = [],
        ?array $pendingChartAccounts = null,
    ): Company {
        $country ??= Country::Canada;

        return DB::transaction(function () use ($user, $name, $isPersonal, $country, $regionCode, $currencyCode, $timezone, $attributes, $pendingChartAccounts) {
            $company = new Company([
                'name' => $name,
                'is_personal' => $isPersonal,
                'address_country' => $country->value,
                'address_region' => $regionCode,
                'currency_code' => $currencyCode ?? $country->defaultCurrencyCode(),
                // When null, the Company creating hook fills it from country/region.
                ...($timezone !== null ? ['timezone' => $timezone] : []),
                ...$attributes,
            ]);

            $company->pendingChartAccounts = $pendingChartAccounts;

            // Arm the dashboard getting-started tips for every new company.
            // Existing companies created before this feature carry no flag and
            // never see the box unless an owner opts in from company settings.
            $settings = $company->settings ?? [];
            $settings['onboarding'] = ['enabled' => true, 'completed' => [], 'dismissed' => false];
            $company->settings = $settings;

            $company->save();

            $orgType = OrganizationType::tryFrom((string) ($attributes['organization_type'] ?? ''));

            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => CompanyRole::Owner,
            ]);

            $user->switchCompany($company);

            // Non-profits don't typically sell to "customers", so a new one starts
            // with the sales-oriented sidebar items hidden for the owner. They can
            // re-show any of them from Settings → Sidebar at any time.
            if ($orgType?->isNonProfit()) {
                $this->hideNonProfitSalesNav($company, $user);
            }

            return $company;
        });
    }

    /**
     * Pre-hide the sales-oriented sidebar items for a new non-profit's owner.
     * Uses a direct insert (with explicit company_id) so it is unaffected by the
     * BelongsToCompany creating hook, which would otherwise force the row onto
     * whatever company is currently bound — wrong on the "add a company" path.
     */
    protected function hideNonProfitSalesNav(Company $company, User $user): void
    {
        $now = now();

        NavPreference::insert(array_map(static fn (string $key): array => [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'item_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::NONPROFIT_HIDDEN_NAV));
    }
}
