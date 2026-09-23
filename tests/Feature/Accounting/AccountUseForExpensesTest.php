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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('switches Use to pay expenses on and off from the account dialog', function () {
    $loan = Account::create([
        'code' => '2710',
        'name' => 'Vehicle Loan - Toyota Sienna',
        'subtype' => AccountSubtype::CurrentLiability->value,
        'type' => AccountSubtype::CurrentLiability->type()->value,
        'normal_balance' => AccountSubtype::CurrentLiability->type()->normalBalance()->value,
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $loan->id)
        ->assertSet('form_use_for_expenses', false)
        ->assertSee(__('Use to pay expenses'))
        ->set('form_use_for_expenses', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($loan->fresh()->use_for_expenses)->toBeTrue();

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $loan->id)
        ->assertSet('form_use_for_expenses', true)
        ->set('form_use_for_expenses', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($loan->fresh()->use_for_expenses)->toBeFalse();
});

it('shows the switch only for liabilities that can opt in', function (AccountSubtype $subtype, bool $shown) {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('form_subtype', $subtype->value)
        ->assertSet('showUseForExpensesField', $shown);
})->with([
    'current liability' => [AccountSubtype::CurrentLiability, true],
    'long-term liability' => [AccountSubtype::LongTermLiability, true],
    'other liability' => [AccountSubtype::OtherLiability, true],
    'bank (always offered)' => [AccountSubtype::Bank, false],
    'credit card (always offered)' => [AccountSubtype::CreditCard, false],
    'accounts payable' => [AccountSubtype::AccountsPayable, false],
    'tax payable' => [AccountSubtype::TaxPayable, false],
    'expense' => [AccountSubtype::Expense, false],
]);

it('hides the switch when editing a system liability', function () {
    $cpp = Account::create([
        'code' => '2499',
        'name' => 'Payroll Clearing',
        'subtype' => AccountSubtype::CurrentLiability->value,
        'type' => AccountSubtype::CurrentLiability->type()->value,
        'normal_balance' => AccountSubtype::CurrentLiability->type()->normalBalance()->value,
        'is_system' => true,
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $cpp->id)
        ->assertSet('showUseForExpensesField', false)
        ->assertDontSee(__('Use to pay expenses'));
});

it('does not keep the flag on an account that cannot opt in', function () {
    $system = Account::create([
        'code' => '2499',
        'name' => 'Payroll Clearing',
        'subtype' => AccountSubtype::CurrentLiability->value,
        'type' => AccountSubtype::CurrentLiability->type()->value,
        'normal_balance' => AccountSubtype::CurrentLiability->type()->normalBalance()->value,
        'is_system' => true,
    ]);

    $saved = app(SaveAccount::class)->handle([
        'code' => '2499', 'name' => 'Payroll Clearing',
        'subtype' => AccountSubtype::CurrentLiability->value, 'use_for_expenses' => true,
    ], $system);
    expect($saved->fresh()->use_for_expenses)->toBeFalse();

    $expense = app(SaveAccount::class)->handle([
        'code' => '6990', 'name' => 'Misc',
        'subtype' => AccountSubtype::Expense->value, 'use_for_expenses' => true,
    ]);
    expect($expense->fresh()->use_for_expenses)->toBeFalse();

    // Retyping a liability away from an eligible subtype clears it too.
    $loan = app(SaveAccount::class)->handle([
        'code' => '2780', 'name' => 'Shareholder Loan',
        'subtype' => AccountSubtype::CurrentLiability->value, 'use_for_expenses' => true,
    ]);
    expect($loan->fresh()->use_for_expenses)->toBeTrue();

    app(SaveAccount::class)->handle([
        'code' => '2780', 'name' => 'Shareholder Loan',
        'subtype' => AccountSubtype::AccountsPayable->value, 'use_for_expenses' => true,
    ], $loan);
    expect($loan->fresh()->use_for_expenses)->toBeFalse();
});
