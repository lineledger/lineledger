<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Budget;
use App\Models\Company;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
});

it('maps the budgets route to the Reports section', function () {
    expect(Section::forRouteName('budgets.index'))->toBe([Section::Reports]);
});

it('lets a member open the budgets index', function () {
    app()->instance('current_company', $this->company);
    Budget::create(['name' => 'B', 'fiscal_year' => 2026]);
    app()->forgetInstance('current_company');

    $this->get(route('budgets.index', ['company' => $this->company->slug]))->assertOk();
});

it('does not resolve another company\'s budget on edit (cross-tenant IDOR)', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreignBudget = Budget::create(['name' => 'Foreign', 'fiscal_year' => 2026]);
    app()->forgetInstance('current_company');

    $this->get(route('budgets.edit', ['company' => $this->company->slug, 'budget' => $foreignBudget->id]))
        ->assertNotFound();
});
