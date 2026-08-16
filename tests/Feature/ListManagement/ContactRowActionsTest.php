<?php

use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Models\AccountingAuditLog;
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

function contactUpdatedAuditRowCount(Contact $contact): int
{
    return AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $contact->company_id)
        ->where('action', AuditAction::ContactUpdated)
        ->where('auditable_id', $contact->id)
        ->count();
}

it('deactivates and reactivates a customer from the customers page', function () {
    $customer = Contact::factory()->customer()->create();

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('toggleActive', $customer->id)
        ->assertHasNoErrors();

    expect($customer->fresh()->is_active)->toBeFalse()
        ->and(contactUpdatedAuditRowCount($customer))->toBe(1);

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('toggleActive', $customer->id)
        ->assertHasNoErrors();

    expect($customer->fresh()->is_active)->toBeTrue()
        ->and(contactUpdatedAuditRowCount($customer))->toBe(2);
});

it('deactivates and reactivates a vendor from the vendors page', function () {
    $vendor = Contact::factory()->vendor()->create();

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('toggleActive', $vendor->id)
        ->assertHasNoErrors();

    expect($vendor->fresh()->is_active)->toBeFalse()
        ->and(contactUpdatedAuditRowCount($vendor))->toBe(1);

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('toggleActive', $vendor->id)
        ->assertHasNoErrors();

    expect($vendor->fresh()->is_active)->toBeTrue()
        ->and(contactUpdatedAuditRowCount($vendor))->toBe(2);
});

it('does not let a member toggle another company\'s contact', function () {
    $otherCompany = Company::factory()->create();

    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->customer()->create();
    app()->instance('current_company', $this->company);

    try {
        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('toggleActive', $foreign->id);
    } catch (Throwable) {
        // findOrFail miss (404) or abort(403) — both mean the write was blocked.
    }

    $fresh = Contact::query()->withoutGlobalScopes()->find($foreign->id);
    expect($fresh->is_active)->toBeTrue();
});

it('does not toggle a pure-customer contact from the vendors page', function () {
    $customer = Contact::factory()->customer()->create();

    try {
        Livewire::test('pages::vendors.index', ['company' => $this->company])
            ->call('toggleActive', $customer->id);
    } catch (Throwable) {
        // findOrFail misses because the contact is not a vendor.
    }

    expect($customer->fresh()->is_active)->toBeTrue();
});
