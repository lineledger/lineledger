<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('selects an existing customer through the invoice combo', function () {
    $customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->assertSet('contact_id', $customer->id)
        ->assertSet('contact_creating', false)
        ->assertSet('contact_query', '');
});

it('creates a new customer inline when posting an invoice', function () {
    $component = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_query', 'Brand New Co')
        ->call('startNewContact')
        ->assertSet('contact_creating', true)
        ->assertSet('new_contact_name', 'Brand New Co')
        ->set('lines.0.account_id', $this->incomeAccount->id)
        ->set('lines.0.description', 'Service')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->call('saveDraft');

    $component->assertHasNoErrors();

    $created = Contact::query()->where('display_name', 'Brand New Co')->first();

    expect($created)->not->toBeNull()
        ->and($created->is_customer)->toBeTrue()
        ->and($created->is_vendor)->toBeFalse()
        ->and($created->company_id)->toBe($this->company->id);
});

it('carries the typed name into the create-customer input', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_query', 'Pacific Crematorium Limited')
        ->call('startNewContact')
        ->assertSet('contact_creating', true)
        ->assertSet('new_contact_name', 'Pacific Crematorium Limited')
        // The create input must render the typed name, not an empty placeholder.
        ->assertSeeHtml('value="Pacific Crematorium Limited"');
});

it('clears a creating-state customer back to search', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_query', 'Typo Co')
        ->call('startNewContact')
        ->assertSet('contact_creating', true)
        ->call('clearContact')
        ->assertSet('contact_creating', false)
        ->assertSet('new_contact_name', '')
        ->assertSet('contact_id', null);
});

it('errors when posting an invoice with no contact and no new name', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->incomeAccount->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '50.00')
        ->call('saveDraft')
        ->assertHasErrors(['contact_id' => 'required']);
});

it('creates a new vendor inline when posting a bill', function () {
    $component = Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_query', 'Fresh Vendor Ltd')
        ->call('startNewContact')
        ->assertSet('contact_creating', true)
        ->assertSet('new_contact_name', 'Fresh Vendor Ltd')
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->set('lines.0.description', 'Supplies')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '75.00')
        ->call('saveDraft');

    $component->assertHasNoErrors();

    $created = Contact::query()->where('display_name', 'Fresh Vendor Ltd')->first();

    expect($created)->not->toBeNull()
        ->and($created->is_vendor)->toBeTrue()
        ->and($created->is_customer)->toBeFalse()
        ->and($created->company_id)->toBe($this->company->id);
});

it('filters customers by search query', function () {
    Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    Contact::create(['display_name' => 'Beta LLC', 'is_customer' => true]);
    Contact::create(['display_name' => 'Acme Holdings', 'is_customer' => true]);

    $names = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_query', 'Acme')
        ->get('customers')
        ->pluck('display_name')
        ->all();

    expect($names)->toContain('Acme Corp', 'Acme Holdings')
        ->and($names)->not->toContain('Beta LLC');
});
