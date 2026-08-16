<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\Country;
use App\Models\User;

test('creating a Canadian company defaults the currency to CAD', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Maple Co',
        country: Country::Canada,
        regionCode: 'BC',
    );

    expect($company->address_country)->toBe('CA');
    expect($company->address_region)->toBe('BC');
    expect($company->currency_code)->toBe('CAD');
});

test('creating a US company defaults the currency to USD', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Eagle Co',
        country: Country::UnitedStates,
        regionCode: 'WA',
    );

    expect($company->address_country)->toBe('US');
    expect($company->address_region)->toBe('WA');
    expect($company->currency_code)->toBe('USD');
});

test('an explicit currency overrides the country default', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Cross-border Co',
        country: Country::Canada,
        regionCode: 'ON',
        currencyCode: 'USD',
    );

    expect($company->currency_code)->toBe('USD');
});
