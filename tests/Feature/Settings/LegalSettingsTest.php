<?php

use App\Models\User;
use Livewire\Livewire;

test('the legal settings tab renders the documents and the agreed date', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.legal')
        ->assertOk()
        ->assertSee('Terms of Service')
        ->assertSee('Privacy Policy')
        // Reference-only documents are listed too.
        ->assertSee('Data Processing Addendum')
        ->assertSee('You agreed on');
});

test('a stale acceptance shows the review-again badge', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->where('document_key', 'terms')->update(['version' => '2000-01-01']);

    $this->actingAs($user);

    Livewire::test('pages::settings.legal')
        ->assertSee('Updated — review again');
});
