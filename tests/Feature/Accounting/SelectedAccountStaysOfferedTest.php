<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Item;
use App\Models\User;
use Livewire\Livewire;

/*
 * A picker offers active accounts, plus whichever the document already holds. An
 * account missing from the list renders as a blank field while its id stays saved,
 * so a since-deactivated account looks lost even though nothing changed.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->closedExpense = expensePaidFromAccount('6991', 'Closed Category', AccountSubtype::Expense);
    $this->closedExpense->update(['is_active' => false]);

    $this->closedBank = expensePaidFromAccount('1091', 'Closed Chequing', AccountSubtype::Bank);
    $this->closedBank->update(['is_active' => false]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @return array<int, int>
 */
function offeredAccountIds(mixed $options): array
{
    return collect($options)
        ->map(fn ($option) => is_array($option) ? $option['value'] : $option->id)
        ->values()
        ->all();
}

it('keeps a deactivated account already on a line', function (string $component, string $options, string $lines) {
    $page = Livewire::test($component, ['company' => $this->company]);

    expect(offeredAccountIds($page->instance()->{$options}()))->not->toContain($this->closedExpense->id);

    $page->set("{$lines}.0.account_id", $this->closedExpense->id);

    expect(offeredAccountIds($page->instance()->{$options}()))->toContain($this->closedExpense->id);
})->with([
    'bill' => ['pages::bills.form', 'expenseAccountOptions', 'lines'],
    'reimbursement' => ['pages::reimbursements.form', 'expenseAccountOptions', 'lines'],
    'journal entry' => ['pages::journal.form', 'accountOptions', 'lines'],
    'journal entry template' => ['pages::journal-entry-templates.form', 'accountOptions', 'lines'],
    'deposit' => ['pages::deposits.form', 'otherAccountOptions', 'otherLines'],
]);

it('keeps the income account an item fills in on a bill line', function () {
    $income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
    $item = Item::factory()->create(['income_account_id' => $income->id, 'expense_account_id' => null, 'is_active' => true]);

    $page = Livewire::test('pages::bills.form', ['company' => $this->company]);

    expect(offeredAccountIds($page->instance()->expenseAccountOptions()))->not->toContain($income->id);

    $page->set('lines.0.item_id', $item->id);

    expect($page->get('lines.0.account_id'))->toBe($income->id)
        ->and(offeredAccountIds($page->instance()->expenseAccountOptions()))->toContain($income->id);
});

it('keeps a deactivated bank on a deposit', function () {
    $page = Livewire::test('pages::deposits.form', ['company' => $this->company]);

    expect($page->instance()->bankAccounts()->pluck('id'))->not->toContain($this->closedBank->id);

    $page->set('bank_account_id', $this->closedBank->id);

    expect($page->instance()->bankAccounts()->pluck('id'))->toContain($this->closedBank->id);
});

it('offers the deactivated bank when duplicating a deposit that used it', function () {
    $source = Deposit::create([
        'bank_account_id' => $this->closedBank->id,
        'deposit_no' => 'DEP-000900',
        'deposit_date' => '2026-01-15',
        'amount_cents' => 0,
    ]);

    $page = Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::deposits.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->closedBank->id);

    // Read through the property: mount lists banks before the source's is known.
    expect($page->instance()->bankAccounts->pluck('id'))->toContain($this->closedBank->id);
});

it('never lists another organization\'s account for a crafted journal line', function () {
    $foreign = Account::withoutGlobalScopes()
        ->where('company_id', Company::factory()->create()->id)
        ->orderBy('code')
        ->firstOrFail();

    $page = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $foreign->id);

    expect(offeredAccountIds($page->instance()->accountOptions()))->not->toContain($foreign->id);
});
