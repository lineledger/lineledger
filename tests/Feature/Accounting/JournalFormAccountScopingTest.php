<?php

use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

it('only shows accounts belonging to the current company in the journal entry form', function () {
    $user = User::factory()->create();

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $companyA->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $companyB->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    // Simulate the Livewire AJAX request lifecycle where the
    // EnsureCompanyMembership HTTP middleware does not run.
    app()->forgetInstance('current_company');

    $options = Livewire::test('pages::journal.form', ['company' => $companyA])
        ->call('addLine')
        ->get('accountOptions');

    expect($options)->not->toBeEmpty();

    $accountIds = array_column($options, 'value');

    $foreignCount = Account::withoutGlobalScopes()
        ->whereIn('id', $accountIds)
        ->where('company_id', '!=', $companyA->id)
        ->count();

    expect($foreignCount)->toBe(0);
});
