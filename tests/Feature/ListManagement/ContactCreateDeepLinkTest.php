<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

/**
 * The cheque/expense payee picker offers "Create ':query' as a new
 * vendor/customer/employee", which opens that index page in a new tab with
 * ?new=<name>. Each page must open its create modal prefilled, drop the
 * param on first render, and save with its own role flag only.
 */
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

/** Inline (not a named dataset) so the name cannot collide with another file's. */
$contactIndexPages = [
    'vendors' => ['pages::vendors.index', 'vendor-form', 'is_vendor', 'vendors.index'],
    'customers' => ['pages::customers.index', 'customer-form', 'is_customer', 'customers.index'],
    'employees' => ['pages::employees.index', 'employee-form', 'is_employee', 'employees.index'],
];

it('opens the create form prefilled from ?new=<name> and clears the param', function (string $page, string $modal) {
    Livewire::withQueryParams(['new' => 'Acme'])
        ->test($page, ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('f_display_name', 'Acme')
        ->assertSet('newRequest', null)
        ->assertDispatched('modal-show', name: $modal);
})->with($contactIndexPages);

it('saves the ?new= prefilled contact with only the page\'s role flag', function (string $page, string $modal, string $flag) {
    Livewire::withQueryParams(['new' => 'Acme'])
        ->test($page, ['company' => $this->company])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: $modal);

    $contact = Contact::query()->where('display_name', 'Acme')->sole();

    expect($contact->company_id)->toBe($this->company->id)
        ->and($contact->{$flag})->toBeTrue()
        ->and($contact->is_active)->toBeTrue()
        ->and($contact->is_other_name)->toBeFalse();

    foreach (['is_customer', 'is_vendor', 'is_employee'] as $other) {
        if ($other !== $flag) {
            expect($contact->{$other})->toBeFalse();
        }
    }
})->with($contactIndexPages);

it('trims and truncates a ?new= name to the 255-character column limit', function (string $page) {
    $name = str_repeat('A', 300);

    Livewire::withQueryParams(['new' => '  '.$name.'  '])
        ->test($page, ['company' => $this->company])
        ->assertSet('f_display_name', str_repeat('A', 255))
        ->assertSet('newRequest', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->where('display_name', str_repeat('A', 255))->exists())->toBeTrue();
})->with($contactIndexPages);

it('does not open any form when neither ?new= nor ?edit= is present', function (string $page) {
    Livewire::test($page, ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('f_display_name', '')
        ->assertSet('newRequest', null)
        ->assertNotDispatched('modal-show');
})->with($contactIndexPages);

it('renders the index page over HTTP with the ?new= link the payee picker builds', function (string $page, string $modal, string $flag, string $route) {
    $this->get(route($route, ['company' => $this->company->slug, 'new' => 'Acme']))
        ->assertOk();
})->with($contactIndexPages);

it('opens the employee edit form pre-filled from ?edit={id} and clears the param', function () {
    $employee = Contact::factory()->create(['is_employee' => true, 'display_name' => 'Deep Link Staffer']);

    Livewire::withQueryParams(['edit' => $employee->id])
        ->test('pages::employees.index', ['company' => $this->company])
        ->assertSet('editingId', $employee->id)
        ->assertSet('f_display_name', 'Deep Link Staffer')
        ->assertSet('editRequest', null)
        ->assertDispatched('modal-show', name: 'employee-form');
});

it('ignores ?edit= on the employees page for a contact that is not an employee', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Not An Employee']);

    Livewire::withQueryParams(['edit' => $vendor->id])
        ->test('pages::employees.index', ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('f_display_name', '')
        ->assertSet('editRequest', null)
        ->assertNotDispatched('modal-show');
});

it('ignores ?edit= on the employees page for another company\'s employee', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->create(['is_employee' => true]);
    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['edit' => $foreign->id])
        ->test('pages::employees.index', ['company' => $this->company])
        ->assertSet('editingId', null)
        ->assertSet('editRequest', null)
        ->assertNotDispatched('modal-show');
});

it('keeps the employees search param working alongside the new deep links', function () {
    Contact::factory()->create(['is_employee' => true, 'display_name' => 'Findable Person']);
    Contact::factory()->create(['is_employee' => true, 'display_name' => 'Someone Else']);

    Livewire::withQueryParams(['q' => 'Findable'])
        ->test('pages::employees.index', ['company' => $this->company])
        ->assertSet('search', 'Findable')
        ->assertSee('Findable Person')
        ->assertDontSee('Someone Else');
});
