<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves a budget from the form, converting dollars to cents', function () {
    Livewire::test('pages::budgets.form', ['company' => $this->company])
        ->set('name', 'FY2026')
        ->set('fiscal_year', 2026)
        ->set('rows.0.account_id', $this->income->id)
        ->set('rows.0.m1', '1,000.50')
        ->set('rows.0.m2', '250.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('budgets.index', $this->company));

    $budget = Budget::firstOrFail();

    expect($budget->name)->toBe('FY2026')
        ->and($budget->lines->first()->month_1_cents)->toBe(100050)
        ->and($budget->lines->first()->month_2_cents)->toBe(25000);
});

it('rejects an invalid money string', function () {
    Livewire::test('pages::budgets.form', ['company' => $this->company])
        ->set('name', 'B')
        ->set('fiscal_year', 2026)
        ->set('rows.0.account_id', $this->income->id)
        ->set('rows.0.m1', 'abc')
        ->call('save')
        ->assertHasErrors('rows.0.m1');
});

it('requires at least one account row', function () {
    Livewire::test('pages::budgets.form', ['company' => $this->company])
        ->set('name', 'B')
        ->set('fiscal_year', 2026)
        ->set('rows.0.account_id', null)
        ->call('save')
        ->assertHasErrors('rows');
});

it('adds and removes grid rows', function () {
    Livewire::test('pages::budgets.form', ['company' => $this->company])
        ->assertCount('rows', 1)
        ->call('addRow')
        ->assertCount('rows', 2)
        ->call('removeRow', 1)
        ->assertCount('rows', 1);
});

it('renders the budget-overview and by-month reports', function () {
    $budget = Budget::create(['name' => 'B', 'fiscal_year' => 2026]);
    $budget->lines()->create(['account_id' => $this->income->id, 'month_1_cents' => 5000]);

    Livewire::test('pages::reports.budget-overview', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->assertOk()
        ->assertSee('Total');

    Livewire::test('pages::reports.budget-vs-actual-by-month', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->assertOk();
});
