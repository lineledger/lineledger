<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

it('saves a customer from a Livewire AJAX action without the EnsureCompanyMembership middleware', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    // Bind container as the initial GET would have done via middleware,
    // then immediately forget it to simulate the subsequent Livewire POST
    // arriving without going through EnsureCompanyMembership.
    app()->forgetInstance('current_company');

    Livewire::test('pages::customers.index', ['company' => $company])
        ->set('f_display_name', 'Acme Customer')
        ->call('save')
        ->assertHasNoErrors();

    $row = Contact::withoutGlobalScopes()->where('display_name', 'Acme Customer')->first();

    expect($row)->not->toBeNull();
    expect($row->company_id)->toBe($company->id);
    expect($row->is_customer)->toBeTrue();
});

it('saves a vendor from a Livewire AJAX action without the EnsureCompanyMembership middleware', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);
    app()->forgetInstance('current_company');

    Livewire::test('pages::vendors.index', ['company' => $company])
        ->set('f_display_name', 'BMO Supplier')
        ->call('save')
        ->assertHasNoErrors();

    $row = Contact::withoutGlobalScopes()->where('display_name', 'BMO Supplier')->first();

    expect($row)->not->toBeNull();
    expect($row->company_id)->toBe($company->id);
    expect($row->is_vendor)->toBeTrue();
});
