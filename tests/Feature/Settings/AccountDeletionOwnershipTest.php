<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

/*
 * An organization always keeps an Owner, so someone who still owns one can't
 * delete their account: they delete the organization first.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Northwind Books']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
});

it('refuses to delete the account of an owner, naming the organizations', function () {
    $second = Company::factory()->create(['name' => 'Alpine Trading']);
    $second->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    Livewire::actingAs($this->user)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('owned')
        ->assertSee('Alpine Trading, Northwind Books')
        ->assertNoRedirect();

    expect($this->user->fresh())->not->toBeNull();
});

it('refuses even before the password is checked', function () {
    Livewire::actingAs($this->user)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser')
        ->assertHasErrors('owned');

    expect($this->user->fresh())->not->toBeNull();
});

it('deletes the account once the organizations it owned are deleted', function () {
    $this->company->delete();

    Livewire::actingAs($this->user)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($this->user->fresh())->toBeNull();
});

it('deletes the account of a member who owns nothing', function () {
    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    Livewire::actingAs($member)
        ->test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    expect($member->fresh())->toBeNull()
        ->and($this->company->fresh()->owner()?->is($this->user))->toBeTrue();
});

it('lists the organizations to delete first in place of the delete button', function () {
    $this->actingAs($this->user);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-test="delete-user-owns-organizations"', escape: false)
        ->assertSee('Northwind Books')
        ->assertDontSee('data-test="delete-user-button"', escape: false);
});
