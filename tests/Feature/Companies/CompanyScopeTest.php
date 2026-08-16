<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

it('routes scoped under {company} require membership', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('dashboard', ['company' => $company->slug]))
        ->assertOk();

    $this->actingAs($other)
        ->get(route('dashboard', ['company' => $company->slug]))
        ->assertForbidden();
});

it('multi-tab: two companies show independent data in the same session', function () {
    $user = User::factory()->create();

    $companyA = Company::factory()->create(['name' => 'Alpha']);
    $companyB = Company::factory()->create(['name' => 'Beta']);

    $companyA->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $companyB->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    $this->get(route('accounts.index', ['company' => $companyA->slug]))->assertOk();
    $this->get(route('accounts.index', ['company' => $companyB->slug]))->assertOk();
});
