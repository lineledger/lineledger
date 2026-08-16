<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('welcome.create-company', absolute: false));

    $this->assertAuthenticated();
});

test('registration records acceptance of the required legal documents', function () {
    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->legalAcceptances()->pluck('document_key')->all())
        ->toContain('terms')
        ->toContain('privacy');

    expect($user->legalAcceptances()->where('document_key', 'terms')->value('version'))
        ->toBe(config('legal.documents.terms.version'));
});

test('registration fails when the terms are not accepted', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('terms');

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});
