<?php

use App\Actions\Inventory\EnsureInventoryAccounts;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\LegalStructure;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Defaults\ChartTemplateBuilder;
use App\Support\SiteSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->companies()->detach();
    $this->user->forceFill(['current_company_id' => null])->save();
    $this->actingAs($this->user);
});

/**
 * @return Collection<int, Account>
 */
function seededAccounts(Company $company): Collection
{
    return Account::withoutGlobalScopes()->where('company_id', $company->id)->get();
}

test('the minimal chart still seeds the system, tax, and inventory scaffolding when those features are on', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Lean Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chartMode', 'minimal')
        // The inventory + employee-reimbursement system accounts are now feature-gated;
        // turn them on (sales tax defaults on) so the full core is seeded.
        ->set('featuresInventory', true)
        ->set('featuresEmployees', true)
        // Not exercising provincial sales tax here — keep the core at exactly 10.
        ->set('chargesPst', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Lean Co')->firstOrFail();
    $accounts = seededAccounts($company);
    $subtypes = $accounts->pluck('subtype')->all();

    // Posting-critical system/control accounts are present even when "minimal".
    foreach ([
        AccountSubtype::AccountsReceivable,
        AccountSubtype::AccountsPayable,
        AccountSubtype::UndepositedFunds,
        AccountSubtype::Inventory,
        AccountSubtype::CostOfGoodsSold,
        AccountSubtype::TaxPayable,
        AccountSubtype::RetainedEarnings,
    ] as $required) {
        expect($subtypes)->toContain($required);
    }

    // Tax + inventory seeding (which query the just-seeded accounts) still ran.
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeTrue();
    expect(TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeTrue();
    expect($company->default_inventory_asset_account_id)->not->toBeNull();
    expect($company->default_cogs_account_id)->not->toBeNull();

    // Minimal means just the 10 core accounts — no operating income/expense lines.
    expect($accounts)->toHaveCount(10);
    expect($accounts->firstWhere('code', '6090'))->toBeNull();
});

test('unchecking inventory, sales tax, and employees omits their system accounts from the seeded chart', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Bare Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chartMode', 'minimal')
        ->set('featuresInventory', false)
        ->set('featuresEmployees', false)
        ->set('chargesTax', false)
        ->set('chargesPst', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Bare Co')->firstOrFail();
    $accounts = seededAccounts($company);

    // The four feature-gated system accounts are absent.
    expect($accounts->firstWhere('code', '1400'))->toBeNull(); // Inventory Asset
    expect($accounts->firstWhere('code', '5000'))->toBeNull(); // Cost of Goods Sold
    expect($accounts->firstWhere('code', '2200'))->toBeNull(); // GST/HST Payable
    expect($accounts->firstWhere('code', '2300'))->toBeNull(); // Employee Reimbursements Payable

    // With no Inventory/COGS account the company defaults stay unset, and with no
    // tax-payable account the tax agency/codes are not seeded (CompanyObserver
    // short-circuits) — both degrade gracefully rather than throwing.
    expect($company->default_inventory_asset_account_id)->toBeNull();
    expect($company->default_cogs_account_id)->toBeNull();
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse();

    // The non-gated core is unaffected.
    expect($accounts->firstWhere('code', '1100'))->not->toBeNull(); // AR
    expect($accounts->firstWhere('code', '2000'))->not->toBeNull(); // AP
    expect($accounts->firstWhere('code', '3000'))->not->toBeNull(); // Opening Balance Equity
});

test('enabling inventory later backfills the Inventory Asset and COGS accounts', function () {
    // Create a company with inventory unchecked, so its chart carries neither account.
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Later Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('featuresInventory', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Later Co')->firstOrFail();
    expect(seededAccounts($company)->firstWhere('code', '1400'))->toBeNull();
    expect($company->default_inventory_asset_account_id)->toBeNull();

    // Turning inventory on backfills the accounts and wires the company defaults.
    app(EnsureInventoryAccounts::class)->handle($company);

    $company->refresh();
    $accounts = seededAccounts($company);

    expect($accounts->firstWhere('code', '1400')?->subtype)->toBe(AccountSubtype::Inventory);
    expect($accounts->firstWhere('code', '5000')?->subtype)->toBe(AccountSubtype::CostOfGoodsSold);
    expect($company->default_inventory_asset_account_id)->not->toBeNull();
    expect($company->default_cogs_account_id)->not->toBeNull();
});

test('the chart review step relabels the equity section to match the organization type', function () {
    // Sole proprietorship (the default) → "Owner's Equity".
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Solo Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('step', 7)
        ->assertSee("Owner's Equity")
        ->assertDontSee('>Equity<', false);

    // Partnership → "Partners' Equity" (the accounts are "Partner Contributions/Draws",
    // so this heading text only appears as the relabelled section header).
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Duo Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'partnership')
        ->set('step', 7)
        ->assertSee("Partners' Equity");

    // Non-profit → "Net Assets".
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Good Cause')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->set('industry', 'non_profit')
        ->set('step', 7)
        ->assertSee('Net Assets');

    // The other sections keep their generic headings.
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Solo Co 2')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('step', 7)
        ->assertSee('Asset')
        ->assertSee('Liability')
        ->assertSee('Income')
        ->assertSee('Expense');
});

test('an industry chart seeds its industry-specific accounts', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'BuildCo')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('industry', 'contractor')
        ->call('createCompany')
        ->assertHasNoErrors();

    $accounts = seededAccounts(Company::where('name', 'BuildCo')->firstOrFail());

    expect($accounts->firstWhere('code', '4000')?->name)->toBe('Construction Revenue');
    expect($accounts->firstWhere('code', '5200')?->name)->toBe('Subcontractor Costs');
});

test('a manufacturing chart seeds the extra inventory accounts without disturbing the system inventory account', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'MakeCo')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('industry', 'manufacturing')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'MakeCo')->firstOrFail();
    $accounts = seededAccounts($company);

    expect($accounts->firstWhere('code', '1420')?->name)->toBe('Work in Process Inventory');

    // The system Inventory account (1400) is the one bound as the inventory default.
    $systemInventory = $accounts->firstWhere('code', '1400');
    expect($systemInventory->is_system)->toBeTrue();
    expect($company->default_inventory_asset_account_id)->toBe($systemInventory->id);
});

test('deselecting an optional account omits it from the seeded chart', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'TrimCo')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('selectedAccounts.6090', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $accounts = seededAccounts(Company::where('name', 'TrimCo')->firstOrFail());

    expect($accounts->firstWhere('code', '6090'))->toBeNull();
    // Locked system accounts are unaffected.
    expect($accounts->firstWhere('code', '1100'))->not->toBeNull();
});

test('a locked system account cannot be deselected even if tampered with', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'TamperCo')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('selectedAccounts.2200', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $accounts = seededAccounts(Company::where('name', 'TamperCo')->firstOrFail());

    expect($accounts->firstWhere('code', '2200')?->name)->toBe('GST/HST Payable');
});

test('a non-profit company relabels equity and seeds donation income', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Helping Hands')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->assertSet('organizationType', 'non_profit')
        ->set('industry', 'non_profit')
        ->call('createCompany')
        ->assertHasNoErrors();

    $accounts = seededAccounts(Company::where('name', 'Helping Hands')->firstOrFail());

    expect($accounts->firstWhere('code', '3900')?->name)->toBe('Net Assets');
    expect($accounts->firstWhere('code', '4000')?->name)->toBe('Donations & Contributions');
    expect($accounts->firstWhere('code', '4100')?->name)->toBe('Grant Revenue');
});

test('choosing a registered charity tier derives the org type and persists the tier fields', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Helping Hands Charity')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->set('legalStructure', 'registered_charity')
        // The legal tier drives the precise organization type.
        ->assertSet('organizationType', 'charity')
        ->set('charityRegistrationNumber', '123456789RR0001')
        ->set('contributionMethod', 'restricted_fund')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Helping Hands Charity')->firstOrFail();

    expect($company->legal_structure)->toBe(LegalStructure::RegisteredCharity);
    expect($company->contribution_method)->toBe(ContributionMethod::RestrictedFund);
    expect($company->charity_registration_number)->toBe('123456789RR0001');
    expect($company->isRegisteredCharity())->toBeTrue();
    expect($company->usesRestrictedFundMethod())->toBeTrue();
});

test('a malformed charity registration number is rejected', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Bad RR Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->set('legalStructure', 'registered_charity')
        ->set('charityRegistrationNumber', 'not-a-number')
        ->call('createCompany')
        ->assertHasErrors(['charityRegistrationNumber']);
});

test('for-profit companies never persist the non-profit tier fields', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Plain Corp')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'corporation')
        ->assertSet('organizationType', 'corporation')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Plain Corp')->firstOrFail();

    expect($company->legal_structure)->toBeNull();
    expect($company->contribution_method)->toBeNull();
    expect($company->charity_registration_number)->toBeNull();
});

test('a non-profit chart seeds the net-asset classes and deferred-contribution liabilities', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Shutter Club')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->assertSet('organizationType', 'non_profit')
        ->set('industry', 'non_profit')
        ->call('createCompany')
        ->assertHasNoErrors();

    $accounts = seededAccounts(Company::where('name', 'Shutter Club')->firstOrFail());

    expect($accounts->firstWhere('code', '3100')?->subtype)->toBe(AccountSubtype::UnrestrictedNetAssets);
    expect($accounts->firstWhere('code', '3200')?->subtype)->toBe(AccountSubtype::RestrictedNetAssets);
    expect($accounts->firstWhere('code', '2500'))->not->toBeNull();
    expect($accounts->firstWhere('code', '2510'))->not->toBeNull();
    // An unincorporated/NPO (non-charity) gets no endowment account.
    expect($accounts->firstWhere('code', '3300'))->toBeNull();
});

test('a club is set up as an unincorporated association on the deferral method with a membership-dues chart', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Shutterbugs Club')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->set('legalStructure', 'unincorporated_association')
        // The unincorporated-association tier derives the club org type and is
        // deferral-only (its contribution-method radio is replaced by a note).
        ->assertSet('organizationType', 'club')
        ->assertSet('contributionMethod', 'deferral')
        ->call('next') // advance to step 2 to render the legal-structure section + club note
        ->assertSee('What is your legal structure?')
        ->assertSee('unincorporated association')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Shutterbugs Club')->firstOrFail();

    expect($company->organization_type->value)->toBe('club');
    expect($company->legal_structure)->toBe(LegalStructure::UnincorporatedAssociation);
    expect($company->contribution_method)->toBe(ContributionMethod::Deferral);

    $accounts = seededAccounts($company);
    expect($accounts->firstWhere('code', '4200')?->name)->toBe('Membership Dues');
    expect($accounts->firstWhere('code', '2510')?->name)->toBe('Deferred Membership Dues');
    // No charity/full-NPO scaffolding for a simple club.
    expect($accounts->firstWhere('code', '2500'))->toBeNull();
});

test('the registered-charity tier is unavailable in the US and is dropped on a country switch', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Cross Border NPO')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('orgCategory', 'nonprofit')
        ->set('legalStructure', 'registered_charity')
        ->assertSet('organizationType', 'charity')
        // Switching to the US drops the CRA-only charity tier back to non-profit corp.
        ->set('country', 'US')
        ->assertSet('legalStructure', 'non_profit_corporation')
        ->assertSet('organizationType', 'non_profit')
        ->set('region', 'WA')
        ->call('next') // render step 2 in the US context
        ->assertSee('What is your legal structure?')
        ->assertDontSee('Registered charity');
});

test('feature toggles and setup metadata persist on the company', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Featured Co')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('featuresInventory', true)
        ->set('featuresEmployees', true)
        ->set('featuresFixedAssets', false)
        ->set('featuresEstimates', false)
        ->set('featuresSalesOrders', false)
        ->set('featuresRecurringInvoices', true)
        ->set('featuresRecurringBills', false)
        ->set('featuresBudgets', true)
        ->set('taxNumber', '12-3456789')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Featured Co')->firstOrFail();

    expect($company->features_inventory)->toBeTrue();
    expect($company->features_employees)->toBeTrue();
    expect($company->features_fixed_assets)->toBeFalse();
    expect($company->features_estimates)->toBeFalse();
    expect($company->features_sales_orders)->toBeFalse();
    expect($company->features_recurring_invoices)->toBeTrue();
    expect($company->features_recurring_bills)->toBeFalse();
    expect($company->features_budgets)->toBeTrue();
    expect($company->tax_number)->toBe('12-3456789');
    expect($company->industry?->value)->toBe('general');
    expect($company->organization_type?->value)->toBe('sole_proprietorship');
    expect($company->setup_completed_at)->not->toBeNull();
});

test('choosing an industry pre-suggests its recommended feature toggles', function () {
    Livewire::test('pages::welcome.setup-wizard')
        // General defaults everything off.
        ->assertSet('featuresInventory', false)
        ->assertSet('featuresEstimates', false)
        ->assertSet('featuresRecurringInvoices', false)
        // Contractor suggests inventory, fixed assets, estimates, employees.
        ->set('industry', 'contractor')
        ->assertSet('featuresInventory', true)
        ->assertSet('featuresFixedAssets', true)
        ->assertSet('featuresEstimates', true)
        ->assertSet('featuresEmployees', true)
        ->assertSet('featuresSalesOrders', false)
        ->assertSet('featuresRecurringInvoices', false)
        // Health & Wellness suggests only recurring invoices.
        ->set('industry', 'health_wellness')
        ->assertSet('featuresRecurringInvoices', true)
        ->assertSet('featuresInventory', false)
        ->assertSet('featuresEstimates', false)
        ->assertSet('featuresEmployees', false);
});

test('the wizard can be walked through every step with next() and then create', function () {
    // Steps 4 (features), 7 (review) and 8 (confirm) have no validation rules;
    // calling next() on them must not trip Livewire's MissingRulesException.
    $component = Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Stepwise Co')
        ->set('country', 'CA')
        ->set('region', 'BC');

    foreach (range(1, 7) as $_) {
        $component->call('next')->assertHasNoErrors();
    }

    $component->assertSet('step', 8)
        ->call('createCompany')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Company::where('name', 'Stepwise Co')->exists())->toBeTrue();
});

test('step two requires an organization type', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Org Co')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('orgCategory', '')
        ->call('next') // step 1 -> 2
        ->call('next') // validate step 2
        ->assertHasErrors(['orgCategory']);
});

test('the country and base currency prefill from the Cloudflare country header', function () {
    Livewire::withHeaders(['CF-IPCountry' => 'CA'])
        ->test('pages::welcome.setup-wizard')
        ->assertSet('country', 'CA')
        ->assertSet('currency', 'CAD');

    Livewire::withHeaders(['CF-IPCountry' => 'US'])
        ->test('pages::welcome.setup-wizard')
        ->assertSet('country', 'US')
        ->assertSet('currency', 'USD');
});

test('an unsupported or missing country header leaves country and currency blank', function () {
    Livewire::withHeaders(['CF-IPCountry' => 'GB'])
        ->test('pages::welcome.setup-wizard')
        ->assertSet('country', '')
        ->assertSet('currency', '');

    Livewire::test('pages::welcome.setup-wizard')
        ->assertSet('country', '')
        ->assertSet('currency', '');
});

test('the timezone defaults to Eastern and is not clobbered by a country change', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->assertSet('timezone', 'America/New_York')
        ->set('timezone', 'America/Los_Angeles')
        ->set('country', 'CA')
        ->assertSet('timezone', 'America/Los_Angeles')
        ->assertSet('currency', 'CAD');
});

test('choosing an unsupported country blanks the base currency for explicit selection', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->assertSet('currency', 'CAD')
        ->set('country', 'XX')
        ->assertSet('currency', '');
});

test('step one requires a country and base currency to be chosen', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Blank Co')
        ->set('country', '')
        ->set('currency', '')
        ->call('next')
        ->assertHasErrors(['country', 'currency']);
});

test('a default company creation still seeds the full jurisdiction chart', function () {
    // Non-wizard creation (factory path) must be unchanged: pendingChartAccounts
    // is null, so the observer falls back to the full default chart.
    $company = Company::factory()->create(['address_country' => 'CA']);

    expect(seededAccounts($company)->count())->toBeGreaterThan(10);
    expect(seededAccounts($company)->firstWhere('code', '6900'))->not->toBeNull();
});

test('detectTimezone autoselects the province from a region-specific browser zone', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->call('detectTimezone', 'America/Halifax')
        ->assertSet('timezone', 'America/Halifax')
        ->assertSet('region', 'NS');
});

test('detectTimezone leaves the province blank for a zone that spans several regions', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->call('detectTimezone', 'America/New_York')
        ->assertSet('timezone', 'America/New_York')
        ->assertSet('region', '');
});

test('detectTimezone never clobbers a province the owner already picked', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->set('region', 'ON')
        ->call('detectTimezone', 'America/Vancouver')
        // Timezone still updates, but the explicit region answer survives.
        ->assertSet('timezone', 'America/Vancouver')
        ->assertSet('region', 'ON');
});

test('detectTimezone runs once so navigating back never re-detects', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->call('detectTimezone', 'America/Vancouver')
        ->assertSet('region', 'BC')
        // A second autodetect (e.g. re-rendering step 1) is ignored entirely.
        ->call('detectTimezone', 'America/Halifax')
        ->assertSet('timezone', 'America/Vancouver')
        ->assertSet('region', 'BC');
});

// --- Step 4: "What do you want to track?" module groups ---------------------

describe('step 4 module groups', function () {
    // SiteSettings caches forever; RefreshDatabase rolls back the row but not the
    // array cache, so clear it after each test to keep state from leaking.
    afterEach(fn () => Cache::forget('site_settings'));

    test('groups the modules under the four subheadings in render order', function () {
        SiteSettings::set('disabled_sections', []);

        Livewire::test('pages::welcome.setup-wizard')
            ->set('country', 'CA') // so Payroll (Canada-only) renders too
            ->set('step', 4)
            // assertSee escapes the needle, so the "&" matches the rendered "&amp;".
            ->assertSee('Sales & Income')
            ->assertSee('Costs & Expenses')
            ->assertSee('Non-Profit & Associations')
            ->assertSee('Planning & Accounting')
            // Every toggle appears, in the grouped order the headings imply.
            ->assertSeeHtmlInOrder([
                'wizard-features-estimates',
                'wizard-features-sales-orders',
                'wizard-features-recurring-invoices',
                'wizard-features-inventory',
                'wizard-features-employees',
                'wizard-features-payroll',
                'wizard-features-fixed-assets',
                'wizard-features-recurring-bills',
                'wizard-features-membership',
                'wizard-features-fundraising',
                'wizard-features-budgets',
                'wizard-features-locations',
                'wizard-features-classes',
            ]);
    });

    test('hides a module whose section the platform admin disabled globally', function () {
        SiteSettings::set('disabled_sections', [
            Section::Inventory->value,
            Section::Fundraising->value,
        ]);

        Livewire::test('pages::welcome.setup-wizard')
            ->set('country', 'CA')
            ->set('step', 4)
            // The globally-disabled modules drop their toggle entirely...
            ->assertDontSeeHtml('wizard-features-inventory')
            ->assertDontSeeHtml('wizard-features-fundraising')
            // ...while their groups and the remaining modules stay put.
            ->assertSee('Costs & Expenses')
            ->assertSee('Non-Profit & Associations')
            ->assertSeeHtml('wizard-features-employees')
            ->assertSeeHtml('wizard-features-membership');
    });

    test('hides payroll when its section is disabled globally, even for a Canadian company', function () {
        SiteSettings::set('disabled_sections', [Section::Payroll->value]);

        Livewire::test('pages::welcome.setup-wizard')
            ->set('country', 'CA')
            ->set('step', 4)
            ->assertDontSeeHtml('wizard-features-payroll')
            // The rest of Costs & Expenses is untouched.
            ->assertSeeHtml('wizard-features-fixed-assets');
    });
});

// --- Step 4: fixed-assets gating shapes the chart ---------------------------

test('disabling fixed assets omits the fixed-asset accounts from the seeded chart', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'No Assets Co')
        ->set('country', 'CA')
        ->set('region', 'ON') // HST province — keeps PST out of this test
        ->set('featuresFixedAssets', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'No Assets Co')->firstOrFail();
    $accounts = seededAccounts($company);

    expect($accounts->firstWhere('code', '1500'))->toBeNull(); // Office Equipment
    expect($accounts->firstWhere('code', '1510'))->toBeNull(); // Accumulated Depreciation
    expect($accounts->where('subtype', AccountSubtype::FixedAsset))->toBeEmpty();
});

test('enabling fixed assets keeps the fixed-asset accounts in the seeded chart', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Assets Co')
        ->set('country', 'CA')
        ->set('region', 'ON')
        ->set('featuresFixedAssets', true)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Assets Co')->firstOrFail();
    expect(seededAccounts($company)->firstWhere('code', '1500'))->not->toBeNull();
});

// --- Step 5: provincial sales tax (PST/RST) --------------------------------

test('a BC company charging PST is seeded with the PST account, agency and code', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'BC Shop')
        ->set('country', 'CA')
        ->set('region', 'BC')
        // chargesTax + chargesPst both default on.
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'BC Shop')->firstOrFail();

    // The provincial liability account exists alongside GST/HST Payable (2200).
    $pstPayable = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2210')->first();
    expect($pstPayable)->not->toBeNull();
    expect($pstPayable->name)->toBe('PST Payable');

    // Federal: CRA points at the system GST/HST Payable account.
    $cra = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Canada Revenue Agency')->first();
    $gstHst = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2200')->first();
    expect($cra->payable_account_id)->toBe($gstHst->id);

    // Provincial: BC's agency points at the PST account, with a non-recoverable 7% code.
    $bc = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'BC Ministry of Finance')->first();
    expect($bc)->not->toBeNull();
    expect($bc->payable_account_id)->toBe($pstPayable->id);

    $pstCode = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'PST-BC')->first();
    expect($pstCode)->not->toBeNull();
    expect($pstCode->rate_basis_points)->toBe(700.0); // basis points are now a float cast
    expect($pstCode->is_recoverable)->toBeFalse();
    expect($pstCode->agency_id)->toBe($bc->id);
});

test('a Manitoba company is seeded with RST terminology', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'MB Store')
        ->set('country', 'CA')
        ->set('region', 'MB')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'MB Store')->firstOrFail();

    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2210')->value('name'))->toBe('RST Payable');
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Manitoba Finance')->exists())->toBeTrue();
    // Load via the model so the float cast normalises the value across MySQL/SQLite.
    $rst = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'RST-MB')->first();
    expect($rst->rate_basis_points)->toBe(700.0);
});

test('a Quebec company is seeded with the QST account, Revenu Québec agency and a recoverable 9.975% code', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'QC Boutique')
        ->set('country', 'CA')
        ->set('region', 'QC')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'QC Boutique')->firstOrFail();

    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2210')->value('name'))->toBe('QST Payable');

    $rq = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Revenu Québec')->first();
    expect($rq)->not->toBeNull();

    // QST is a value-added tax — recoverable, unlike PST/RST — at the fractional rate.
    $qst = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'QST-QC')->first();
    expect($qst)->not->toBeNull();
    expect($qst->rate_basis_points)->toBe(997.5);
    expect($qst->is_recoverable)->toBeTrue();
    expect($qst->agency_id)->toBe($rq->id);
});

test('records the GST/HST and PST account numbers on their respective agencies', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'BC Numbered')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('taxNumber', '123456789 RT0001')
        ->set('pstNumber', 'PST-1234-5678')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'BC Numbered')->firstOrFail();

    // GST/HST number lands on the company (invoice display) and the CRA agency.
    expect($company->tax_number)->toBe('123456789 RT0001');
    $cra = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Canada Revenue Agency')->first();
    expect($cra->registration_number)->toBe('123456789 RT0001');

    // PST number lands on the provincial agency.
    $bc = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'BC Ministry of Finance')->first();
    expect($bc->registration_number)->toBe('PST-1234-5678');
});

test('shows a separate account-number field for GST and for PST', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('step', 5)
        ->assertSeeHtml('wizard-tax-number')
        ->assertSeeHtml('wizard-pst-number')
        // Ontario (HST, no PST) shows only the GST/HST field.
        ->set('region', 'ON')
        ->assertSeeHtml('wizard-tax-number')
        ->assertDontSeeHtml('wizard-pst-number');
});

test('a BC company that opts out of PST gets no provincial account, agency or code', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'BC No PST')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chargesPst', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'BC No PST')->firstOrFail();

    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2210')->exists())->toBeFalse();
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'BC Ministry of Finance')->exists())->toBeFalse();
    expect(TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'PST-BC')->exists())->toBeFalse();

    // The federal GST/HST setup is unaffected.
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Canada Revenue Agency')->exists())->toBeTrue();
});

test('an Ontario (HST) company gets no provincial sales-tax scaffolding', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'ON Co')
        ->set('country', 'CA')
        ->set('region', 'ON')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'ON Co')->firstOrFail();

    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2210')->exists())->toBeFalse();
    // Only the CRA agency exists — no provincial one.
    expect(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

test('the PST toggle shows for PST provinces and hides for HST/GST-only ones', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('step', 5)
        ->assertSeeHtml('wizard-charges-pst')
        ->set('region', 'ON')
        ->assertDontSeeHtml('wizard-charges-pst')
        ->set('region', 'MB')
        ->assertSeeHtml('wizard-charges-pst');
});

/**
 * Create an existing company the acting user belongs to, with two custom accounts:
 * a parent ("Marketing") and a GIFI-coded child ("Online Ads") nested under it.
 * Returns the source company.
 */
function sourceCompanyWithCustomChart(User $user): Company
{
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $parent = Account::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => '6500',
        'name' => 'Marketing',
        'type' => AccountSubtype::Expense->type(),
        'subtype' => AccountSubtype::Expense,
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
        'is_system' => false,
        'is_active' => true,
        'description' => 'Marketing spend',
    ]);

    Account::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'parent_id' => $parent->id,
        'code' => '6510',
        'name' => 'Online Ads',
        'type' => AccountSubtype::Expense->type(),
        'subtype' => AccountSubtype::Expense,
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
        'gifi_code' => '8520',
        'is_system' => false,
        'is_active' => true,
    ]);

    return $company;
}

test('copy mode clones an existing chart with names, descriptions, GIFI codes, and parent nesting', function () {
    $source = sourceCompanyWithCustomChart($this->user);

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Cloned Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chartMode', 'copy')
        ->set('sourceCompanyId', $source->id)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Cloned Co')->firstOrFail();
    $accounts = seededAccounts($company);

    $parent = $accounts->firstWhere('code', '6500');
    $child = $accounts->firstWhere('code', '6510');

    expect($parent)->not->toBeNull();
    expect($parent->name)->toBe('Marketing');
    expect($parent->description)->toBe('Marketing spend');

    expect($child)->not->toBeNull();
    expect($child->name)->toBe('Online Ads');
    expect($child->gifi_code)->toBe('8520');
    // The parent link is re-resolved against the NEW company's account ids.
    expect($child->parent_id)->toBe($parent->id);

    // A copy is a full clone — the source's system/control accounts come over too.
    expect($accounts->firstWhere('code', '1100'))->not->toBeNull(); // AR
    expect($accounts->firstWhere('code', '2000'))->not->toBeNull(); // AP
});

test('trimming a parent in review leaves the copied child as a valid top-level account', function () {
    $source = sourceCompanyWithCustomChart($this->user);

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Trimmed Clone')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chartMode', 'copy')
        ->set('sourceCompanyId', $source->id)
        // Deselect the parent (6500) but keep the child (6510).
        ->set('selectedAccounts.6500', false)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Trimmed Clone')->firstOrFail();
    $accounts = seededAccounts($company);

    expect($accounts->firstWhere('code', '6500'))->toBeNull();

    $child = $accounts->firstWhere('code', '6510');
    expect($child)->not->toBeNull();
    expect($child->parent_id)->toBeNull();
});

test('copy mode requires a source organization before advancing past step 3', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Needs Source')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('step', 3)
        ->set('chartMode', 'copy')
        ->call('next')
        ->assertHasErrors('sourceCompanyId')
        ->assertSet('step', 3);
});

test('copy mode rejects a source company the user is not a member of', function () {
    // A company the acting user has no membership in.
    $foreign = Company::factory()->create();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Should Not Exist')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('chartMode', 'copy')
        ->set('sourceCompanyId', $foreign->id)
        ->call('createCompany')
        ->assertHasErrors('sourceCompanyId');

    expect(Company::where('name', 'Should Not Exist')->exists())->toBeFalse();
});

test('fromCompanyAccounts maps an existing chart into sorted preview rows', function () {
    $source = sourceCompanyWithCustomChart($this->user);

    $accounts = Account::withoutGlobalScopes()
        ->where('company_id', $source->id)
        ->whereIn('code', ['6500', '6510'])
        ->orderBy('code')
        ->get();

    $rows = ChartTemplateBuilder::fromCompanyAccounts($accounts);

    expect($rows)->toHaveCount(2);

    $childRow = collect($rows)->firstWhere('code', '6510');
    expect($childRow['name'])->toBe('Online Ads');
    expect($childRow['gifi_code'])->toBe('8520');
    expect($childRow['parent_code'])->toBe('6500'); // parent expressed by code, not id
    expect($childRow['subtype'])->toBe(AccountSubtype::Expense);
    expect($childRow['locked'])->toBeFalse();
    expect($childRow['default_selected'])->toBeTrue();
});
