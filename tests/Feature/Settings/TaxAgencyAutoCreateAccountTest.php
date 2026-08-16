<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

it('auto-creates a Tax Payable account when none is chosen', function () {
    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyCreate')
        ->set('a_name', 'City of Toronto')
        ->call('saveAgency')
        ->assertHasNoErrors();

    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'City of Toronto')
        ->firstOrFail();

    $account = $agency->payableAccount;

    expect($account)->not->toBeNull()
        ->and($account->subtype)->toBe(AccountSubtype::TaxPayable)
        ->and($account->name)->toBe('City of Toronto Payable')
        ->and($account->code)->toStartWith('22')
        ->and($account->code)->not->toBe('2200');
});

it('prefills the name and account name from the catalog and uses them', function () {
    // QST is not seeded for a company without a Quebec region, so it is offered.
    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyCreate')
        ->set('a_catalog_key', 'QST-QC')
        ->assertSet('a_name', 'Revenu Québec')
        ->assertSet('a_account_name', 'QST Payable')
        ->call('saveAgency')
        ->assertHasNoErrors();

    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'Revenu Québec')
        ->firstOrFail();

    expect($agency->payableAccount->name)->toBe('QST Payable');
});

it('still accepts an explicitly selected existing account', function () {
    $existing = Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::TaxPayable->value)
        ->orderBy('code')
        ->firstOrFail();

    $before = Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::TaxPayable->value)
        ->count();

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyCreate')
        ->set('a_name', 'Reuses Existing')
        ->set('a_payable_account_id', $existing->id)
        ->call('saveAgency')
        ->assertHasNoErrors();

    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'Reuses Existing')
        ->firstOrFail();

    $after = Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::TaxPayable->value)
        ->count();

    expect($agency->payable_account_id)->toBe($existing->id)
        ->and($after)->toBe($before);
});

it('does not offer or duplicate an authority that already exists', function () {
    $catalog = Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->get('authorityCatalog');

    $names = array_column($catalog, 'name');

    // CRA is seeded for every Canadian company, so it is filtered out of the picker.
    expect($names)->not->toContain('Canada Revenue Agency');
});
