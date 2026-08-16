<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    // An active seeded bank plus a deactivated one.
    $this->activeBank = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->firstOrFail();

    $this->inactiveBank = Account::query()->create([
        'company_id' => $this->company->id,
        'code' => '1099',
        'name' => 'Closed Chequing',
        'type' => AccountSubtype::Bank->type(),
        'subtype' => AccountSubtype::Bank,
        'normal_balance' => AccountSubtype::Bank->type()->normalBalance(),
        'is_active' => false,
        'is_system' => false,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

test('the bank register account selector hides inactive accounts', function () {
    $accounts = Livewire::test('pages::banking.register', ['company' => $this->company])
        ->instance()
        ->bankAccounts();

    expect($accounts->pluck('id'))
        ->toContain($this->activeBank->id)
        ->not->toContain($this->inactiveBank->id);
});

test('the reconcile account selector hides inactive accounts', function () {
    $accounts = Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->instance()
        ->bankAccounts();

    expect($accounts->pluck('id'))
        ->toContain($this->activeBank->id)
        ->not->toContain($this->inactiveBank->id);
});

test('an edit form keeps an already-selected inactive bank account visible', function () {
    // Simulate editing a record whose bank account was later deactivated: the
    // selector must still offer it so the selection is never silently dropped.
    $accounts = Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->set('from_account_id', $this->inactiveBank->id)
        ->instance()
        ->bankAccounts();

    expect($accounts->pluck('id'))
        ->toContain($this->inactiveBank->id)
        ->toContain($this->activeBank->id);
});
