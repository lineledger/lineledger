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

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openStatement', $customer->id)
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

    expect(fn () => Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openStatement', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses to open a statement for a vendor-only contact', function () {
    $vendor = Contact::factory()->vendor()->create();

    expect(fn () => Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openStatement', $vendor->id))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the statement triggers on the customers page', function () {
    Contact::factory()->customer()->create();

    $this->get(route('customers.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('data-test="customer-open-balance"', escape: false)
        ->assertSee('data-test="customer-statement-button"', escape: false);
});
