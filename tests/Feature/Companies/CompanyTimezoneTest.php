<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * The bug: transaction dates default with the app's UTC clock, so an entry made
 * late evening in a western timezone posts on the next calendar day. Defaults
 * must resolve "today" in the company's configured timezone instead.
 *
 * 2026-05-25 06:00 UTC is 2026-05-24 23:00 in Pacific time — the exact scenario.
 */
function actingMemberOf(Company $company): User
{
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    test()->actingAs($user);

    // Mirror the Livewire AJAX lifecycle, where the company-binding HTTP
    // middleware has not run.
    app()->forgetInstance('current_company');

    return $user;
}

test('journal form defaults the entry date to today in the company timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-25 06:00:00', 'UTC'));

    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    actingMemberOf($company);

    $entryDate = Livewire::test('pages::journal.form', ['company' => $company])
        ->get('entryDate');

    expect($entryDate)->toBe('2026-05-24');
});

test('a UTC company under the same clock still defaults to the UTC day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-25 06:00:00', 'UTC'));

    $company = Company::factory()->create(['timezone' => 'UTC']);
    actingMemberOf($company);

    $entryDate = Livewire::test('pages::journal.form', ['company' => $company])
        ->get('entryDate');

    expect($entryDate)->toBe('2026-05-25');
});

test('balance sheet defaults the as-of date to today in the company timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-25 06:00:00', 'UTC'));

    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    actingMemberOf($company);

    $asOf = Livewire::test('pages::reports.balance-sheet', ['company' => $company])
        ->get('asOf');

    expect($asOf)->toBe('2026-05-24');
});

test('country resolves a region-aware default timezone from the curated picker set', function () {
    $options = array_values(Company::timezoneOptions());

    expect(Country::Canada->defaultTimezone('BC'))->toBe('America/Los_Angeles');
    expect(Country::Canada->defaultTimezone('NS'))->toBe('America/Halifax');
    expect(Country::Canada->defaultTimezone('ON'))->toBe('America/New_York');
    expect(Country::Canada->defaultTimezone(null))->toBe('America/New_York');
    expect(Country::UnitedStates->defaultTimezone('CA'))->toBe('America/Los_Angeles');
    expect(Country::UnitedStates->defaultTimezone('NY'))->toBe('America/New_York');
    expect(Country::UnitedStates->defaultTimezone(null))->toBe('America/New_York');

    // Every default must be a friendly option in the settings picker.
    foreach ([Country::Canada, Country::UnitedStates] as $country) {
        expect($options)->toContain($country->defaultTimezone());
    }
});

test('a new company is seeded with the jurisdiction default timezone', function () {
    $company = Company::factory()->forCountry(Country::UnitedStates, 'WA')->create();

    expect($company->timezone)->toBe('America/Los_Angeles');
});

test('CreateCompany honours an explicitly chosen timezone', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Globetrotter Ltd',
        country: Country::Canada,
        regionCode: 'ON',
        timezone: 'Asia/Tokyo',
    );

    expect($company->timezone)->toBe('Asia/Tokyo');
});

test('currentDateTime returns now in the company timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-25 06:00:00', 'UTC'));

    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);

    expect($company->currentDateTime()->toDateString())->toBe('2026-05-24');
    expect($company->currentDateTime()->getTimezone()->getName())->toBe('America/Vancouver');
});
