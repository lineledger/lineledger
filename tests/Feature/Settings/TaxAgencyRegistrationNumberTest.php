<?php

use App\Enums\CompanyRole;
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

it('persists a registration number when saving a tax agency', function () {
    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'Canada Revenue Agency')
        ->firstOrFail();

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyEdit', $agency->id)
        ->set('a_registration_number', '123456789 RT0001')
        ->call('saveAgency')
        ->assertHasNoErrors();

    expect($agency->fresh()->registration_number)->toBe('123456789 RT0001');
});

it('stores null when the registration number is blank', function () {
    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'Canada Revenue Agency')
        ->firstOrFail();
    $agency->update(['registration_number' => '999']);

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyEdit', $agency->id)
        ->set('a_registration_number', '   ')
        ->call('saveAgency')
        ->assertHasNoErrors();

    expect($agency->fresh()->registration_number)->toBeNull();
});

it('rejects registration numbers longer than 50 characters', function () {
    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('name', 'Canada Revenue Agency')
        ->firstOrFail();

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyEdit', $agency->id)
        ->set('a_registration_number', str_repeat('x', 51))
        ->call('saveAgency')
        ->assertHasErrors(['a_registration_number']);
});
