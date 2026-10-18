<?php

use App\Enums\Country;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

function freshOnboardingUser(): User
{
    $user = User::factory()->create();
    $user->companies()->detach();
    $user->forceFill(['current_company_id' => null])->save();

    test()->actingAs($user);

    return $user;
}

test('the wizard creates a US company and redirects to the dashboard', function () {
    $user = freshOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'My Startup')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('currency', 'USD')
        ->call('createCompany')
        ->assertHasNoErrors()
        ->assertRedirect();

    $company = Company::where('name', 'My Startup')->firstOrFail();

    expect($company->address_country)->toBe('US');
    expect($company->address_region)->toBe('WA');
    expect($company->currency_code)->toBe('USD');
    expect($company->jurisdiction)->toBe(Country::UnitedStates);
    expect($user->fresh()->current_company_id)->toBe($company->id);
});

test('the wizard creates a UK company with VAT defaults', function () {
    $user = freshOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'My UK Startup')
        ->set('country', 'GB')
        ->set('region', 'ENG')
        ->set('currency', 'GBP')
        ->set('organizationType', 'corporation')
        ->set('orgCategory', 'corporation')
        ->set('companyRegistrationNumber', '12345678')
        ->call('createCompany')
        ->assertHasNoErrors()
        ->assertRedirect();

    $company = Company::where('name', 'My UK Startup')->firstOrFail();

    expect($company->address_country)->toBe('GB');
    expect($company->address_region)->toBe('ENG');
    expect($company->currency_code)->toBe('GBP');
    expect($company->jurisdiction)->toBe(Country::UnitedKingdom);
    expect($company->company_registration_number)->toBe('12345678');
    expect($user->fresh()->current_company_id)->toBe($company->id);
});

test('the wizard shows a company number field for UK limited companies', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Ltd Co')
        ->set('country', 'GB')
        ->set('region', 'ENG')
        ->set('currency', 'GBP')
        ->call('next') // step 1 → 2
        ->set('orgCategory', 'corporation')
        ->assertSee(__('Company number'))
        ->assertSee('data-test="wizard-company-registration-number"', false);

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Trader Co')
        ->set('country', 'GB')
        ->set('region', 'ENG')
        ->set('currency', 'GBP')
        ->call('next')
        ->set('orgCategory', 'sole_proprietorship')
        ->assertDontSee('data-test="wizard-company-registration-number"', false);
});

test('the wizard persists the chosen fiscal year start month', function () {
    freshOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Fiscal Co')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('currency', 'USD')
        ->set('fiscalYearStartMonth', 7)
        ->call('createCompany')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Company::where('name', 'Fiscal Co')->firstOrFail()->fiscal_year_start_month)->toBe(7);
});

test('the wizard rejects an out-of-range fiscal year start month on step one', function () {
    freshOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Bad Fiscal Co')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('fiscalYearStartMonth', 13)
        ->call('next')
        ->assertHasErrors(['fiscalYearStartMonth']);
});

test('the wizard requires a Canadian province when Canada is chosen', function () {
    freshOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'No Province Co')
        ->set('country', 'CA')
        ->set('region', '')
        ->call('next')
        ->assertHasErrors(['region']);
});

test('the welcome route redirects to the picker when the user already has a company', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('welcome.create-company'))
        ->assertRedirect(route('companies.picker'));
});

test('the companies.setup route renders the wizard for a user who already has a company', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('companies.setup'))
        ->assertOk()
        ->assertSee('Setup your organization');
});
