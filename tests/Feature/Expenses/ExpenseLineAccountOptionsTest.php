<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\User;
use Livewire\Livewire;

/**
 * The expense form's line picker mirrors the cheque form's: every active
 * account, of every type, revenue included.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('lists an active account of every type on an expense line', function () {
    $ids = Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->instance()->lineAccountOptions()->pluck('id');

    foreach (AccountType::cases() as $type) {
        $account = Account::query()->where('type', $type->value)->where('is_active', true)->orderBy('code')->firstOrFail();

        expect($ids)->toContain($account->id);
    }
});

it('leaves an inactive account off the expense list unless a line already uses it', function () {
    $this->income->update(['is_active' => false]);

    $component = Livewire::test('pages::expenses.form', ['company' => $this->company]);

    expect($component->instance()->lineAccountOptions()->pluck('id'))->not->toContain($this->income->id);

    $component->set('lines.0.account_id', $this->income->id);

    expect($component->instance()->lineAccountOptions()->pluck('id'))->toContain($this->income->id);
});

it('posts an expense line coded to a revenue account', function () {
    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('payee_name', 'Refunded customer')
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.amount', '25.00')
        ->call('postExpense')
        ->assertHasNoErrors();

    $leg = JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_type', Expense::class))
        ->where('account_id', $this->income->id)
        ->firstOrFail();

    expect((int) $leg->debit_cents)->toBe(2500);
});
