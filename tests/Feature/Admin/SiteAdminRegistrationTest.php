<?php

use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('site_settings');
});

it('grants site admin to the first user to register', function () {
    $this->post(route('register.store'), [
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'first@example.com')->value('site_admin'))->toBeTrue();
});

it('does not grant site admin to subsequent users', function () {
    User::factory()->create(); // an existing first user

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'second@example.com')->value('site_admin'))->toBeFalse();
});

it('shows the closed page when registrations are disabled', function () {
    User::factory()->create(); // so the first-user bootstrap path is not taken
    SiteSettings::set('registrations_enabled', false);

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Registration is closed');
});

it('rejects a registration submission when registrations are disabled', function () {
    User::factory()->create();
    SiteSettings::set('registrations_enabled', false);

    $this->post(route('register.store'), [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('still lets the very first user register even when registrations are disabled', function () {
    SiteSettings::set('registrations_enabled', false); // no users exist yet

    $this->post(route('register.store'), [
        'name' => 'Bootstrap Admin',
        'email' => 'bootstrap@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'bootstrap@example.com')->value('site_admin'))->toBeTrue();
});

it('hides the sign-up link on the login page when registrations are disabled', function () {
    User::factory()->create();
    SiteSettings::set('registrations_enabled', false);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up');
});
