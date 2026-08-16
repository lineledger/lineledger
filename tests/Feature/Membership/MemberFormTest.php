<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Member;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true]);
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
});

afterEach(fn () => app()->forgetInstance('current_company'));

test('a full country name is rejected with a validation error, not a database error', function () {
    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'new')
        ->set('new_display_name', 'Pat Member')
        ->set('new_billing_country', 'Canada')
        ->call('save')
        ->assertHasErrors(['new_billing_country' => 'max']);

    expect(Contact::query()->count())->toBe(0);
    expect(Member::query()->count())->toBe(0);
});

test('a two-letter country code is uppercased and stored on the new contact', function () {
    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'new')
        ->set('new_display_name', 'Pat Member')
        ->set('new_billing_country', 'ca')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contact = Contact::query()->sole();
    expect($contact->billing_country)->toBe('CA');
    expect(Member::query()->where('contact_id', $contact->id)->exists())->toBeTrue();
});

test('a blank country is stored as null', function () {
    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'new')
        ->set('new_display_name', 'Pat Member')
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->sole()->billing_country)->toBeNull();
});
