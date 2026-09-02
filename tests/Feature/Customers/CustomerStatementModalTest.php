<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

it('opens the statement modal with date defaults and the customer email prefilled', function () {
    $customer = Contact::factory()->customer()->create(['email' => 'rain@example.com']);

    $today = $this->company->currentDateTime();

    Livewire::test('customer-statement-modal', ['company' => $this->company])
        ->dispatch('open-customer-statement', id: $customer->id)
        ->assertHasNoErrors()
        ->assertSet('statementCustomerId', $customer->id)
        ->assertSet('statementType', 'open-invoices')
        ->assertSet('statementAsOf', $today->toDateString())
        ->assertSet('statementEnd', $today->toDateString())
        ->assertSet('statementStart', $today->startOfYear()->toDateString())
        ->assertSet('statementToEmail', 'rain@example.com');
});

it('refuses to open a statement for another company\'s contact', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->customer()->create();
    app()->instance('current_company', $this->company);

    expect(fn () => Livewire::test('customer-statement-modal', ['company' => $this->company])
        ->call('open', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses to open a statement for a vendor-only contact', function () {
    $vendor = Contact::factory()->vendor()->create();

    expect(fn () => Livewire::test('customer-statement-modal', ['company' => $this->company])
        ->call('open', $vendor->id))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the statement triggers on the customers page', function () {
    Contact::factory()->customer()->create();

    $this->get(route('customers.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('data-test="customer-open-balance"', escape: false)
        ->assertSee('data-test="customer-statement-button"', escape: false)
        ->assertSee('data-test="customer-statement-modal"', escape: false);
});

it('pre-fills the activity range and as-of date when opened with start and end', function () {
    $customer = Contact::factory()->customer()->create();

    Livewire::test('customer-statement-modal', ['company' => $this->company])
        ->dispatch('open-customer-statement', id: $customer->id, start: '2026-02-01', end: '2026-03-31')
        ->assertSet('statementStart', '2026-02-01')
        ->assertSet('statementEnd', '2026-03-31')
        ->assertSet('statementAsOf', '2026-03-31')
        ->assertDispatched('modal-show', name: 'customer-statement');
});

it('falls back to year-to-date when the supplied start or end is not a real date', function () {
    $customer = Contact::factory()->customer()->create();

    $today = $this->company->currentDateTime();

    Livewire::test('customer-statement-modal', ['company' => $this->company])
        ->dispatch('open-customer-statement', id: $customer->id, start: 'nope', end: '2026-13-99')
        ->assertSet('statementStart', $today->startOfYear()->toDateString())
        ->assertSet('statementEnd', $today->toDateString())
        ->assertSet('statementAsOf', $today->toDateString());
});
