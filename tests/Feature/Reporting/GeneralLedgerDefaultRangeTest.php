<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function glDefaultsFor(int $fiscalYearStartMonth): Company
{
    $company = Company::factory()->create(['fiscal_year_start_month' => $fiscalYearStartMonth, 'timezone' => 'UTC']);
    $company->members()->attach(test()->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $company);
    test()->actingAs(test()->user);

    return $company;
}

it('defaults the range to the start of the current fiscal year through today', function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 4, 12));
    $company = glDefaultsFor(4);

    Livewire::test('pages::reports.general-ledger', ['company' => $company])
        ->assertSet('startDate', '2026-04-01')
        ->assertSet('endDate', '2026-09-04');
});

it('rolls the default start back a year when today is before the fiscal year start month', function () {
    $this->travelTo(CarbonImmutable::create(2026, 2, 15, 12));
    $company = glDefaultsFor(4);

    Livewire::test('pages::reports.general-ledger', ['company' => $company])
        ->assertSet('startDate', '2025-04-01')
        ->assertSet('endDate', '2026-02-15');
});

it('uses January 1st for a calendar-year company', function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 4, 12));
    $company = glDefaultsFor(1);

    Livewire::test('pages::reports.general-ledger', ['company' => $company])
        ->assertSet('startDate', '2026-01-01');
});

it('keeps an explicit start from the query string', function () {
    $this->travelTo(CarbonImmutable::create(2026, 9, 4, 12));
    $company = glDefaultsFor(4);

    Livewire::withQueryParams(['start' => '2026-08-01'])
        ->test('pages::reports.general-ledger', ['company' => $company])
        ->assertSet('startDate', '2026-08-01');
});
