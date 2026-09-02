<?php

use App\Enums\CompanyRole;
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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('opens the customer edit form pre-filled from ?edit={id} and clears the param', function () {
    $customer = Contact::factory()->customer()->create(['display_name' => 'Deep Link Co']);

    Livewire::withQueryParams(['edit' => $customer->id])
        ->test('pages::customers.index', ['company' => $this->company])
        ->assertSet('editingId', $customer->id)
        ->assertSet('f_display_name', 'Deep Link Co')
        ->assertSet('editRequest', null)
        ->assertDispatched('modal-show', name: 'customer-form');
});

it('ignores ?edit= for another company\'s contact', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->customer()->create();
    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['edit' => $foreign->id])
        ->test('pages::customers.index', ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('editRequest', null)
        ->assertNotDispatched('modal-show');
});

it('opens the vendor edit form pre-filled from ?edit={id}', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Deep Link Supplies']);

    Livewire::withQueryParams(['edit' => $vendor->id])
        ->test('pages::vendors.index', ['company' => $this->company])
        ->assertSet('editingId', $vendor->id)
        ->assertSet('f_display_name', 'Deep Link Supplies')
        ->assertSet('editRequest', null)
        ->assertDispatched('modal-show', name: 'vendor-form');
});
