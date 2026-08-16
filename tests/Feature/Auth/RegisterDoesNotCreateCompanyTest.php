<?php

use App\Models\User;

test('registering a new user does not auto-create a personal company', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'newuser@example.com')->firstOrFail();

    expect($user->companies()->count())->toBe(0);
    expect($user->current_company_id)->toBeNull();
});

test('an authenticated user with no companies is redirected to the welcome page', function () {
    $user = User::factory()->create();

    $user->companies()->detach();
    $user->forceFill(['current_company_id' => null])->save();

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect(route('welcome.create-company'));
});
