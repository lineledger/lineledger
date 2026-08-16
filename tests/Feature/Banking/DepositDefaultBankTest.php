<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->primaryBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('defaults a new deposit to the lowest-code active bank when there is no prior deposit', function () {
    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->primaryBank->id);
});

it('defaults a new deposit to the bank account used on the most recent deposit', function () {
    // A higher-code bank so it is NOT the lowest-code default.
    $savings = app(SaveAccount::class)->handle([
        'code' => '1090', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    // Record a prior deposit into Savings via the real form.
    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $savings->id)
        ->set('otherLines', [[
            'account_id' => $this->income->id,
            'contact_id' => null,
            'description' => 'Owner contribution',
            'amount' => '100.00',
            'class_id' => null,
            'location_id' => null,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    // A brand-new deposit should now remember Savings, not the lowest-code bank.
    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $savings->id);
});

it('falls back to the lowest-code active bank when the last-used account is deactivated', function () {
    $savings = app(SaveAccount::class)->handle([
        'code' => '1090', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $savings->id)
        ->set('otherLines', [[
            'account_id' => $this->income->id,
            'contact_id' => null,
            'description' => 'Owner contribution',
            'amount' => '100.00',
            'class_id' => null,
            'location_id' => null,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $savings->forceFill(['is_active' => false])->save();

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->primaryBank->id);
});
