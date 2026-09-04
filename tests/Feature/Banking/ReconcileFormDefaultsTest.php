<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\User;
use App\Services\Reconciliation\BankReconciliationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->fees = Account::query()->where('code', '6010')->first(); // Bank Charges
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('keeps the service charge and interest dates in step with the statement date until edited', function () {
    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->set('statementDate', '2026-10-31')
        ->assertSet('serviceChargeDate', '2026-10-31')
        ->assertSet('interestDate', '2026-10-31')
        ->set('serviceChargeDate', '2026-10-15')
        ->set('statementDate', '2026-11-30')
        ->assertSet('serviceChargeDate', '2026-10-15')
        ->assertSet('interestDate', '2026-11-30');
});

it('pre-selects the default chart\'s bank charges account', function () {
    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('serviceChargeAccountId', $this->fees->id);
});

it('finds bank-fee and interest accounts by name when the chart uses other codes', function () {
    $this->fees->update(['code' => '7010', 'name' => 'Bank Fees']);

    $interest = Account::query()
        ->where('type', AccountType::Income->value)
        ->orderByDesc('code')
        ->first();
    $interest->update(['name' => 'Interest Income']);

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('serviceChargeAccountId', $this->fees->id)
        ->assertSet('interestAccountId', $interest->id);
});

it('remembers the accounts chosen last time for the same bank account', function () {
    $other = Account::query()
        ->where('type', AccountType::Expense->value)
        ->where('id', '!=', $this->fees->id)
        ->orderBy('code')
        ->first();

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->set('endingBalance', '0.00')
        ->set('serviceChargeAccountId', $other->id)
        ->call('startReconciliation');

    expect(BankReconciliation::query()->forAccount($this->bank->id)->inProgress()->exists())->toBeTrue()
        ->and($this->company->fresh()->reconciliationDefaults($this->bank->id)['service_charge_account_id'])->toBe($other->id);

    app(BankReconciliationService::class)->cancel(
        BankReconciliation::query()->forAccount($this->bank->id)->inProgress()->firstOrFail(),
    );

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('serviceChargeAccountId', $other->id);
});

it('falls back to the account recorded on the last reconciliation', function () {
    $other = Account::query()
        ->where('type', AccountType::Expense->value)
        ->where('id', '!=', $this->fees->id)
        ->orderBy('code')
        ->first();

    BankReconciliation::factory()->completed()->create([
        'company_id' => $this->company->id,
        'account_id' => $this->bank->id,
        'statement_date' => '2026-07-31',
        'service_charge_cents' => 500,
        'service_charge_account_id' => $other->id,
    ]);

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('serviceChargeAccountId', $other->id);
});
