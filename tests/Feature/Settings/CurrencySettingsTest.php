<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the currencies settings page', function () {
    $this->get(route('settings.currencies', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Currencies');
});

it('enables a foreign currency from the page', function () {
    Livewire::test('pages::settings.currencies', ['company' => $this->company])
        ->set('f_currency_code', 'USD')
        ->call('enable')
        ->assertHasNoErrors();

    expect(CompanyCurrency::withoutGlobalScopes()->where('company_id', $this->company->id)->where('currency_code', 'USD')->where('is_active', true)->exists())->toBeTrue()
        ->and($this->company->fresh()->multicurrency_enabled)->toBeTrue();
});

it('saves a manual exchange rate', function () {
    Livewire::test('pages::settings.currencies', ['company' => $this->company])
        ->set('f_currency_code', 'USD')
        ->call('enable')
        ->set('r_currency_code', 'USD')
        ->set('r_rate', '1.38')
        ->set('r_date', '2026-05-01')
        ->call('saveRate')
        ->assertHasNoErrors();

    expect(ExchangeRate::query()->where('company_id', $this->company->id)->where('base_code', 'USD')->where('quote_code', 'CAD')->where('source', 'manual')->exists())->toBeTrue();
});
