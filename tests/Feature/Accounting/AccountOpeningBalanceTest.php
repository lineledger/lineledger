<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\PostAccountOpeningBalance;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function createWithOpeningBalance(Company $company, array $set = [])
{
    $component = Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate');

    foreach (array_merge([
        'form_code' => '1450',
        'form_name' => 'Prepaid Rent',
        'form_subtype' => AccountSubtype::CurrentAsset->value,
        'form_opening_balance' => '1,000.00',
        'form_opening_balance_as_of' => '2026-06-01',
    ], $set) as $key => $value) {
        $component->set($key, $value);
    }

    return $component->call('save');
}

function openingEntry(): ?JournalEntry
{
    return JournalEntry::query()->where('memo', 'Opening balance')->first();
}

it('posts a debit to the account and a credit to Opening Balance Equity for a debit-normal account', function () {
    createWithOpeningBalance($this->company)->assertHasNoErrors();

    $account = Account::query()->where('code', '1450')->firstOrFail();
    $obe = Account::query()->where('name', 'Opening Balance Equity')->firstOrFail();

    $entry = openingEntry();
    expect($entry)->not->toBeNull();
    expect($entry->isPosted())->toBeTrue();
    expect($entry->entry_date->toDateString())->toBe('2026-06-01');

    $accountLine = $entry->lines->firstWhere('account_id', $account->id);
    $obeLine = $entry->lines->firstWhere('account_id', $obe->id);

    expect($accountLine->debit_cents)->toBe(100000);
    expect($accountLine->credit_cents)->toBe(0);
    expect($obeLine->credit_cents)->toBe(100000);
    expect($obeLine->debit_cents)->toBe(0);
});

it('resolves the opening-balance account by its non-profit net-assets name', function () {
    // A non-profit chart names code 3000 "Opening Balance Net Assets" instead of
    // "Opening Balance Equity"; posting must still find it as the balancing line.
    Account::query()->where('name', Account::OPENING_BALANCE_EQUITY_NAME)
        ->update(['name' => Account::OPENING_BALANCE_NET_ASSETS_NAME]);

    createWithOpeningBalance($this->company)->assertHasNoErrors();

    $obe = Account::query()->where('name', Account::OPENING_BALANCE_NET_ASSETS_NAME)->firstOrFail();
    $obeLine = openingEntry()?->lines->firstWhere('account_id', $obe->id);

    expect($obeLine)->not->toBeNull();
    expect($obeLine->credit_cents)->toBe(100000);
});

it('flips the sides for a credit-normal account', function () {
    createWithOpeningBalance($this->company, [
        'form_code' => '2855',
        'form_name' => 'Shareholder Loan',
        'form_subtype' => AccountSubtype::LongTermLiability->value,
        'form_opening_balance' => '500.00',
    ])->assertHasNoErrors();

    $account = Account::query()->where('code', '2855')->firstOrFail();
    $obe = Account::query()->where('name', 'Opening Balance Equity')->firstOrFail();

    $entry = openingEntry();
    $accountLine = $entry->lines->firstWhere('account_id', $account->id);
    $obeLine = $entry->lines->firstWhere('account_id', $obe->id);

    expect($accountLine->credit_cents)->toBe(50000);
    expect($accountLine->debit_cents)->toBe(0);
    expect($obeLine->debit_cents)->toBe(50000);
    expect($obeLine->credit_cents)->toBe(0);
});

it('creates no journal entry for a blank or zero opening balance', function () {
    createWithOpeningBalance($this->company, ['form_opening_balance' => ''])->assertHasNoErrors();
    createWithOpeningBalance($this->company, [
        'form_code' => '1460',
        'form_name' => 'Prepaid Insurance',
        'form_opening_balance' => '0',
    ])->assertHasNoErrors();

    expect(Account::query()->where('code', '1450')->exists())->toBeTrue();
    expect(Account::query()->where('code', '1460')->exists())->toBeTrue();
    expect(openingEntry())->toBeNull();
});

it('rejects a locked period and rolls back the account creation', function () {
    $this->company->update(['lock_date' => '2026-06-05']);

    createWithOpeningBalance($this->company, ['form_opening_balance_as_of' => '2026-06-01'])
        ->assertHasErrors('form_opening_balance_as_of');

    // One transaction: the rejected opening entry takes the account with it.
    expect(Account::query()->where('code', '1450')->exists())->toBeFalse();
    expect(openingEntry())->toBeNull();
});

it('rejects a negative opening balance', function () {
    createWithOpeningBalance($this->company, ['form_opening_balance' => '-100.00'])
        ->assertHasErrors('form_opening_balance');

    expect(Account::query()->where('code', '1450')->exists())->toBeFalse();
});

it('surfaces a friendly error and creates nothing when Opening Balance Equity is missing', function () {
    Account::query()->where('name', 'Opening Balance Equity')->firstOrFail()
        ->update(['name' => 'Founder Equity']);

    createWithOpeningBalance($this->company)
        ->assertHasErrors('form_opening_balance');

    expect(Account::query()->where('code', '1450')->exists())->toBeFalse();
    expect(openingEntry())->toBeNull();
});

it('hides the opening balance fields when editing an account', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $bank->id)
        ->assertDontSeeHtml('data-test="account-opening-balance"')
        ->assertDontSeeHtml('data-test="account-opening-balance-as-of"');
});

it('hides the opening balance fields for profit-and-loss subtypes', function () {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->assertDontSeeHtml('data-test="account-opening-balance"');
});

it('hides the opening balance fields for sub-ledger control subtypes', function () {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('form_subtype', AccountSubtype::AccountsReceivable->value)
        ->assertDontSeeHtml('data-test="account-opening-balance"');
});

it('swaps the fields for a journal-entry hint when a foreign currency is selected', function () {
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('form_subtype', AccountSubtype::Bank->value)
        ->assertSeeHtml('data-test="account-opening-balance"')
        ->set('form_currency_code', 'USD')
        ->assertDontSeeHtml('data-test="account-opening-balance"')
        ->assertSeeHtml('data-test="account-opening-balance-fx-hint"');
});

it('rejects non-positive amounts at the action level', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    expect(fn () => app(PostAccountOpeningBalance::class)->handle($bank, -100, '2026-06-01'))
        ->toThrow(ValidationException::class);
});
