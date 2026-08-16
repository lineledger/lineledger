<?php

use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function orgTypeOwnedCompany(User $user, array $attributes = []): Company
{
    $company = Company::factory()->create($attributes);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    return $company;
}

test('a company with no organization type can set it once from the company profile', function () {
    $company = orgTypeOwnedCompany($this->user, ['organization_type' => null, 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSee('This can be set only once')
        ->set('organizationType', OrganizationType::Corporation->value)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->organization_type)->toBe(OrganizationType::Corporation);
});

test('leaving the organization type unselected keeps it unset', function () {
    $company = orgTypeOwnedCompany($this->user, ['organization_type' => null, 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->organization_type)->toBeNull();
});

test('an already-set organization type cannot be changed from the company profile', function () {
    $company = orgTypeOwnedCompany($this->user, ['organization_type' => 'corporation', 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSee('cannot be changed')
        ->set('organizationType', OrganizationType::Charity->value)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->organization_type)->toBe(OrganizationType::Corporation);
});

test('charity is not offered to a United States company', function () {
    $company = orgTypeOwnedCompany($this->user, ['organization_type' => null, 'address_country' => 'US']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('organizationType', OrganizationType::Charity->value)
        ->call('updateCompany')
        ->assertHasErrors(['organizationType']);

    expect($company->refresh()->organization_type)->toBeNull();
});

test('setting a non-profit type unlocks the non-profit card on the next load', function () {
    $company = orgTypeOwnedCompany($this->user, ['organization_type' => null, 'address_country' => 'CA']);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertDontSee('Non-profit & charity')
        ->set('organizationType', OrganizationType::NonProfit->value)
        ->call('updateCompany')
        ->assertHasNoErrors();

    Livewire::test('pages::companies.edit', ['company' => $company->refresh()])
        ->assertSee('Non-profit & charity');
});
