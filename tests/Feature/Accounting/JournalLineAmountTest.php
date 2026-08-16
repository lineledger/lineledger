<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function bankAccountForAmounts(): Account
{
    return Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
}

it('does not crash when a debit amount is mid-typed as a trailing dot', function () {
    $bank = bankAccountForAmounts();

    // Reproduces the live-update 500: typing "6" then "." syncs "6." to the
    // server, which used to throw "Invalid money string: [6.]" in the totals.
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $bank->id)
        ->set('lines.0.debit', '6.')
        ->assertOk()
        ->assertSet('lines.0.debit', '6.');
})->with([
    'trailing dot' => ['6.'],
    'too many decimals' => ['6.789'],
    'lone dot' => ['.'],
]);

it('treats an unparseable transient amount as zero in the running total', function () {
    $bank = bankAccountForAmounts();

    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $bank->id)
        ->set('lines.0.debit', '6.');

    expect($component->instance()->totalDebitsCents)->toBe(0);

    // Once the cents land, the total reflects the real amount.
    $component->set('lines.0.debit', '6.50');
    expect($component->instance()->totalDebitsCents)->toBe(650);
});

it('rejects a malformed amount on submit with a field error instead of a 500', function () {
    $bank = bankAccountForAmounts();

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $bank->id)
        ->set('lines.0.debit', '6.')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '6.00')
        ->call('postEntry')
        ->assertHasErrors('lines.0.debit');

    expect(JournalEntry::query()->count())->toBe(0);
});
