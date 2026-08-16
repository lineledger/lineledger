<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function arAccount(): Account
{
    return Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->firstOrFail();
}

function apAccount(): Account
{
    return Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->firstOrFail();
}

function bankAccountForContacts(): Account
{
    return Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
}

it('scopes line contact options to the role the account requires and the search query', function () {
    $ar = arAccount();

    $acme = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Acme Co']);
    $other = Contact::factory()->customer()->create(['company_id' => $this->company->id, 'display_name' => 'Globex']);
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id, 'display_name' => 'Acme Supplies']);

    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.contact_query', 'Acme');

    $ids = $component->instance()->lineContactOptions(0)->pluck('id')->all();

    // Customer role + "Acme" search => only the matching customer, never the vendor.
    expect($ids)->toContain($acme->id)
        ->not->toContain($other->id)
        ->not->toContain($vendor->id);
});

it('returns no line contact options for a non-AR/AP account', function () {
    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', bankAccountForContacts()->id);

    expect($component->instance()->lineContactOptions(0))->toBeEmpty();
});

it('selects a customer through the combo handler', function () {
    $ar = arAccount();
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.contact_query', 'something')
        ->call('selectLineContact', 0, $customer->id)
        ->assertSet('lines.0.contact_id', $customer->id)
        ->assertSet('lines.0.contact_query', '')
        ->assertSet('lines.0.contact_creating', false);
});

it('blocks posting an Accounts Receivable line without a customer', function () {
    $ar = arAccount();
    $bank = bankAccountForContacts();

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasErrors('lines.0.contact_id');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('blocks posting an Accounts Payable line without a vendor', function () {
    $ap = apAccount();
    $bank = bankAccountForContacts();

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $bank->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $ap->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasErrors('lines.1.contact_id');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('rejects a vendor on an Accounts Receivable line', function () {
    $ar = arAccount();
    $bank = bankAccountForContacts();
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.contact_id', $vendor->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasErrors('lines.0.contact_id');
});

it('posts an Accounts Receivable line with a customer and stores the contact', function () {
    $ar = arAccount();
    $bank = bankAccountForContacts();
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryNo', 'JE-CONTACT-1')
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    $entry = JournalEntry::query()->where('entry_no', 'JE-CONTACT-1')->firstOrFail();
    $arLine = $entry->lines()->where('account_id', $ar->id)->firstOrFail();

    expect($entry->is_posted)->toBeTrue();
    expect($arLine->contact_id)->toBe($customer->id);
    expect($arLine->debit_cents)->toBe(Money::fromString('100.00')->cents);
});

it('creates a new customer inline from the combo and posts the entry', function () {
    $ar = arAccount();
    $bank = bankAccountForContacts();

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryNo', 'JE-CONTACT-NEW')
        ->set('lines.0.account_id', $ar->id)
        ->set('lines.0.contact_query', 'Brand New Customer')
        ->call('startNewLineContact', 0)
        ->assertSet('lines.0.contact_creating', true)
        ->assertSet('lines.0.new_contact_name', 'Brand New Customer')
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'Brand New Customer')->firstOrFail();
    expect($customer->is_customer)->toBeTrue();
    expect($customer->company_id)->toBe($this->company->id);

    $entry = JournalEntry::query()->where('entry_no', 'JE-CONTACT-NEW')->firstOrFail();
    expect($entry->lines()->where('account_id', $ar->id)->value('contact_id'))->toBe($customer->id);
});

it('does not require a contact on non-AR/AP lines and drops a stale contact', function () {
    $bank = bankAccountForContacts();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryNo', 'JE-CONTACT-2')
        // Pick AR first, choose a customer, then switch the account away from AR.
        ->set('lines.0.account_id', arAccount()->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.account_id', $bank->id)
        ->assertSet('lines.0.contact_id', null)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $income->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();

    $entry = JournalEntry::query()->where('entry_no', 'JE-CONTACT-2')->firstOrFail();
    expect($entry->lines()->where('account_id', $bank->id)->value('contact_id'))->toBeNull();
});
