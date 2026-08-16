<?php

use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\LegalStructure;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function ownedCompany(User $user, array $attributes = []): Company
{
    $company = Company::factory()->create($attributes);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    return $company;
}

test('the non-profit card is shown for a charity and hidden for a for-profit company', function () {
    $charity = ownedCompany($this->user, ['organization_type' => 'charity', 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $charity])
        ->assertSee('Non-profit & charity');

    $corp = ownedCompany($this->user, ['organization_type' => 'corporation', 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $corp])
        ->assertDontSee('Non-profit & charity');
});

test('a charity can save its legal tier, registration number, and contribution method', function () {
    $company = ownedCompany($this->user, ['organization_type' => 'charity', 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('legalStructure', LegalStructure::RegisteredCharity->value)
        ->set('charityRegistrationNumber', '123456789RR0001')
        ->set('contributionMethod', ContributionMethod::RestrictedFund->value)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->legal_structure)->toBe(LegalStructure::RegisteredCharity);
    expect($company->charity_registration_number)->toBe('123456789RR0001');
    expect($company->contribution_method)->toBe(ContributionMethod::RestrictedFund);
    expect($company->isRegisteredCharity())->toBeTrue();
});

test('a malformed registration number is rejected on the settings page', function () {
    $company = ownedCompany($this->user, ['organization_type' => 'charity', 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('legalStructure', LegalStructure::RegisteredCharity->value)
        ->set('charityRegistrationNumber', '12345')
        ->call('updateCompany')
        ->assertHasErrors(['charityRegistrationNumber']);
});

test('changing the contribution method does not rewrite organization type or other settings', function () {
    $company = ownedCompany($this->user, [
        'organization_type' => 'non_profit',
        'address_country' => 'CA',
        'contribution_method' => 'deferral',
    ]);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('contributionMethod', ContributionMethod::RestrictedFund->value)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->contribution_method)->toBe(ContributionMethod::RestrictedFund);
    expect($company->organization_type->value)->toBe('non_profit');
});
