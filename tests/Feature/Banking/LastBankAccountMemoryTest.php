<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Banking\LastBankAccount;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->primaryBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();

    // A higher-code bank, so it is never the lowest-code default.
    $this->savings = app(SaveAccount::class)->handle([
        'code' => '1090', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('opens a new cheque on the lowest-code bank when nothing is remembered', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->primaryBank->id);
});

it('remembers the bank account chosen on the cheque form', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->savings->id);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->savings->id);
});

it('carries the account chosen on a cheque across the other banking screens', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->savings->id);

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('account_id', $this->savings->id);

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->assertSet('account_id', $this->savings->id);

    Livewire::test('pages::banking.import', ['company' => $this->company])
        ->assertSet('account_id', $this->savings->id);

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->savings->id);

    Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->assertSet('from_account_id', $this->savings->id);

    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->assertSet('payment_account_id', $this->savings->id);
});

it('carries an account chosen on the register back to the cheque form', function () {
    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->savings->id);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->savings->id);
});

it('lets an explicit ?account= link win over the memory and become the new memory', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->savings->id);

    // A "Reconcile this account" style link carries the account explicitly.
    $this->get(route('banking.register', ['company' => $this->company->slug, 'account' => $this->primaryBank->id]))
        ->assertOk();

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->primaryBank->id);
});

it('falls back to the lowest-code bank when the remembered account is deactivated', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->savings->id);

    $this->savings->forceFill(['is_active' => false])->save();

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->primaryBank->id);
});

it('never hands back an account belonging to another company', function () {
    LastBankAccount::remember($this->company, $this->savings->id);

    $other = Company::factory()->create();
    $otherBank = Account::withoutGlobalScopes()
        ->where('company_id', $other->id)
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->firstOrFail();

    // A different company keeps its own memory, so the savings account never leaks.
    expect(LastBankAccount::recall($other, [$otherBank->id]))->toBeNull()
        ->and(LastBankAccount::recall($this->company, [$this->savings->id]))->toBe($this->savings->id);
});

it('clears the memory when the selector is emptied', function () {
    LastBankAccount::remember($this->company, $this->savings->id);
    LastBankAccount::remember($this->company, null);

    expect(LastBankAccount::recall($this->company, [$this->savings->id]))->toBeNull();
});

it('still remembers the account after the session ends', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->savings->id);

    // Signing out (or a 2-hour idle timeout) ends the session; the memory is per user, not per session.
    session()->flush();

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('account_id', $this->savings->id);
});

it('does not hand one user’s account to a colleague in the same company', function () {
    LastBankAccount::remember($this->company, $this->savings->id);

    $colleague = User::factory()->create();
    $this->company->members()->attach($colleague, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($colleague);

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('account_id', $this->primaryBank->id);
});
