<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Fortify registers POST /register with no throttle at all, so this is enforced
 * in App\Actions\Fortify\CreateNewUser. Beyond the fake accounts themselves,
 * every accepted registration sends a verification email — an unthrottled form
 * is also a way to burn the sending domain's reputation.
 */
function attemptRegistration(int $n, array $overrides = []): TestResponse
{
    // A successful registration signs the new user in, and Fortify guards
    // register.store with `guest:web` — without logging out, every attempt after
    // the first would bounce off that redirect and never reach the throttle.
    auth('web')->logout();

    return test()->post(route('register.store'), array_merge([
        'name' => 'John Doe',
        'email' => "user{$n}@example.com",
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ], $overrides));
}

test('registrations from one address are throttled once the limit is reached', function () {
    config(['fortify.registration_throttle.max_attempts' => 3]);

    // The very first user bootstraps the platform and is exempt, so seed one.
    User::factory()->create();

    attemptRegistration(1)->assertSessionHasNoErrors();
    attemptRegistration(2)->assertSessionHasNoErrors();
    attemptRegistration(3)->assertSessionHasNoErrors();

    attemptRegistration(4)->assertSessionHasErrors('email');

    expect(User::where('email', 'user4@example.com')->exists())->toBeFalse();
});

test('failed attempts count toward the throttle', function () {
    config(['fortify.registration_throttle.max_attempts' => 2]);

    User::factory()->create();

    // Missing `terms` — rejected, but the attempt still cost the app work.
    attemptRegistration(1, ['terms' => null])->assertSessionHasErrors('terms');

    attemptRegistration(2)->assertSessionHasNoErrors();

    attemptRegistration(3)->assertSessionHasErrors('email');
});

test('the first user is never throttled so a fresh install can be bootstrapped', function () {
    config(['fortify.registration_throttle.max_attempts' => 0]);

    expect(User::count())->toBe(0);

    attemptRegistration(1)->assertSessionHasNoErrors();

    expect(User::where('email', 'user1@example.com')->first()?->site_admin)->toBeTrue();
});

test('the throttle can be disabled entirely', function () {
    config(['fortify.registration_throttle.max_attempts' => 0]);

    User::factory()->create();

    foreach (range(1, 6) as $n) {
        attemptRegistration($n)->assertSessionHasNoErrors();
    }

    expect(User::where('email', 'user6@example.com')->exists())->toBeTrue();
});
