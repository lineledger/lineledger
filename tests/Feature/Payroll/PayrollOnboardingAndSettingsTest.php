<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->companies()->detach();
    $this->user->forceFill(['current_company_id' => null])->save();
    $this->actingAs($this->user);
});

function ownedPayrollCompany(User $user, array $attributes = []): Company
{
    $company = Company::factory()->create($attributes);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    return $company;
}

// --- New-company wizard -----------------------------------------------------

it('enables payroll from the setup wizard for a Canadian company and seeds payroll accounts', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Payroll Startup')
        ->set('country', 'CA')
        ->set('region', 'ON')
        ->set('featuresPayroll', true)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Payroll Startup')->firstOrFail();

    expect($company->features_payroll)->toBeTrue()
        ->and($company->usesPayroll())->toBeTrue();

    // The Canadian chart seeds the system payroll accounts.
    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '2440')->exists())->toBeTrue();
});

it('never enables payroll for a US company even if the flag is forced on', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'US Startup')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('featuresPayroll', true)
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'US Startup')->firstOrFail();
    expect($company->features_payroll)->toBeFalse();
});

// --- Company settings -------------------------------------------------------

it('shows the payroll feature toggle in settings only for Canadian companies', function () {
    $ca = ownedPayrollCompany($this->user, ['address_country' => 'CA']);
    Livewire::test('pages::companies.edit', ['company' => $ca])
        ->assertSeeHtml('company-features-payroll-input');

    $us = ownedPayrollCompany($this->user, ['address_country' => 'US']);
    Livewire::test('pages::companies.edit', ['company' => $us])
        ->assertDontSeeHtml('company-features-payroll-input');
});

it('enables payroll from settings and backfills payroll accounts on an existing company', function () {
    $company = ownedPayrollCompany($this->user, ['address_country' => 'CA', 'features_payroll' => false]);

    // Simulate a company created before payroll existed.
    Account::withoutGlobalScopes()->where('company_id', $company->id)
        ->whereIn('code', ['2400', '2410', '2420', '2430', '2440', '6200', '6210', '6220', '6230'])
        ->forceDelete();

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('featuresPayroll', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();
    expect($company->features_payroll)->toBeTrue();

    // Accounts were backfilled so pay runs can post.
    foreach (['2400', '2440', '6200'] as $code) {
        expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->exists())->toBeTrue();
    }
});

it('saves Quebec employer-levy rates from settings as basis points', function () {
    $company = ownedPayrollCompany($this->user, ['address_country' => 'CA', 'features_payroll' => true]);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSet('featuresPayroll', true)
        ->set('qhsfRatePct', 1.92)
        ->set('cnesstRatePct', 2.0)
        ->set('wsdrfApplicable', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();
    expect($company->qhsf_rate_bp)->toBe(192)
        ->and($company->cnesst_rate_bp)->toBe(200)
        ->and($company->wsdrf_applicable)->toBeTrue();
});

it('ignores the payroll flag in settings for a US company', function () {
    $company = ownedPayrollCompany($this->user, ['address_country' => 'US', 'features_payroll' => false]);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('featuresPayroll', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->features_payroll)->toBeFalse();
});

// --- Settings: globally-disabled sections hide their feature toggle ----------

describe('company settings module gating', function () {
    // SiteSettings caches forever; RefreshDatabase rolls back the row but not the
    // array cache, so clear it after each test to keep state from leaking.
    afterEach(fn () => Cache::forget('site_settings'));

    it('hides feature toggles whose section is disabled platform-wide', function () {
        SiteSettings::set('disabled_sections', [
            Section::Inventory->value,
            Section::Fundraising->value,
        ]);

        $company = ownedPayrollCompany($this->user, ['address_country' => 'CA']);

        Livewire::test('pages::companies.edit', ['company' => $company])
            ->assertDontSeeHtml('company-features-inventory-input')
            ->assertDontSeeHtml('company-features-fundraising-input')
            // Sections left enabled still render their toggle.
            ->assertSeeHtml('company-features-employees-input')
            ->assertSeeHtml('company-features-membership-input');
    });

    it('hides the payroll toggle when payroll is disabled platform-wide, even for a Canadian company', function () {
        SiteSettings::set('disabled_sections', [Section::Payroll->value]);

        $company = ownedPayrollCompany($this->user, ['address_country' => 'CA']);

        Livewire::test('pages::companies.edit', ['company' => $company])
            ->assertDontSeeHtml('company-features-payroll-input');
    });

    it('keeps the payroll toggle visible under a per-company admin override despite the platform-wide disable', function () {
        SiteSettings::set('disabled_sections', [Section::Payroll->value]);

        $company = ownedPayrollCompany($this->user, [
            'address_country' => 'CA',
            'payroll_admin_enabled_at' => now(),
        ]);

        Livewire::test('pages::companies.edit', ['company' => $company])
            ->assertSeeHtml('company-features-payroll-input');
    });
});
