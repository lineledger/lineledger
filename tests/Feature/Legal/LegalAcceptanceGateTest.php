<?php

use App\Models\User;
use Livewire\Livewire;

test('a user with no acceptance records is redirected to the acceptance gate', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->delete();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('legal.accept'));
});

test('an established user passes the gate', function () {
    // The factory records acceptance of the current versions.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirectContains($user->currentCompany->slug);
});

test('accepting on the gate records every outstanding document and clears the gate', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->delete();

    $this->actingAs($user);

    Livewire::test('pages::legal.accept')
        ->set('agree', true)
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    expect($user->fresh()->legalAcceptances()->pluck('document_key')->all())
        ->toContain('terms')
        ->toContain('privacy');

    // Gate now clears.
    $this->get(route('home'))->assertRedirectContains($user->currentCompany->slug);
});

test('the gate refuses to continue until the box is checked', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->delete();

    $this->actingAs($user);

    Livewire::test('pages::legal.accept')
        ->call('accept')
        ->assertHasErrors('agree');

    expect($user->fresh()->legalAcceptances()->count())->toBe(0);
});

test('a newer document version re-triggers the gate for an already-accepted user', function () {
    $user = User::factory()->create();

    // User has accepted the current versions, so the gate is clear...
    $this->actingAs($user)->get(route('home'))->assertRedirectContains($user->currentCompany->slug);

    // ...until the Terms of Service is bumped.
    config(['legal.documents.terms.version' => '2099-01-01']);

    $this->get(route('home'))->assertRedirect(route('legal.accept'));
});
