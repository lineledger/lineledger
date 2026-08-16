<?php

use App\Enums\Country;
use App\Models\Company;

test('country cannot be changed after creation', function () {
    $company = Company::factory()->forCountry(Country::Canada)->create();

    expect(fn () => $company->update(['address_country' => 'US']))
        ->toThrow(DomainException::class);

    expect($company->fresh()->address_country)->toBe('CA');
});

test('changing other fields on a company still works', function () {
    $company = Company::factory()->forCountry(Country::UnitedStates)->create();

    $company->update(['name' => 'New Name', 'address_region' => 'CA']);

    expect($company->fresh()->name)->toBe('New Name');
    expect($company->fresh()->address_region)->toBe('CA');
    expect($company->fresh()->address_country)->toBe('US');
});

test('the jurisdiction accessor returns the correct Country case', function () {
    $ca = Company::factory()->forCountry(Country::Canada)->create();
    $us = Company::factory()->forCountry(Country::UnitedStates)->create();

    expect($ca->jurisdiction)->toBe(Country::Canada);
    expect($us->jurisdiction)->toBe(Country::UnitedStates);
});
