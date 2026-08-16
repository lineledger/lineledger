<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('surfaces a validation error instead of a 500 when posting into a reconciled period', function () {
    BankReconciliation::factory()->completed()->create([
        'company_id' => $this->company->id,
        'account_id' => $this->bank->id,
        'statement_date' => '2026-04-30',
    ]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-04-15')
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasErrors('lines')
        ->assertNoRedirect();
});
