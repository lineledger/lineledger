<?php

use App\Models\User;
use App\Services\Security\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Turnstile is off unless both keys are configured, so every test that wants it
 * active opts in explicitly. That default is the property the first test pins:
 * a self-hosted install which never sets keys must behave exactly as it did
 * before the feature existed.
 */
function enableTurnstile(): void
{
    config([
        'turnstile.enabled' => true,
        'turnstile.site_key' => 'test-site-key',
        'turnstile.secret_key' => 'test-secret-key',
    ]);
}

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ], $overrides);
}

test('turnstile is inert until both keys are configured', function () {
    config(['turnstile.enabled' => true, 'turnstile.site_key' => null, 'turnstile.secret_key' => null]);

    Http::fake();

    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasNoErrors();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('a registration with no turnstile token is rejected and creates no user', function () {
    enableTurnstile();
    Http::fake();

    $this->post(route('register.store'), registrationPayload())
        ->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    $this->assertGuest();

    // A missing token never reaches Cloudflare — there is nothing to verify.
    Http::assertNothingSent();
});

test('a registration with a token cloudflare accepts goes through', function () {
    enableTurnstile();
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $this->post(route('register.store'), registrationPayload([
        Turnstile::RESPONSE_FIELD => 'a-valid-token',
    ]))->assertSessionHasNoErrors();

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request['response'] === 'a-valid-token'
        && $request['secret'] === 'test-secret-key');
});

test('a registration with a token cloudflare rejects is blocked', function () {
    enableTurnstile();
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]),
    ]);

    $this->post(route('register.store'), registrationPayload([
        Turnstile::RESPONSE_FIELD => 'a-forged-token',
    ]))->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('registration fails closed when cloudflare is unreachable', function () {
    enableTurnstile();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $this->post(route('register.store'), registrationPayload([
        Turnstile::RESPONSE_FIELD => 'a-token',
    ]))->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('login fails open when cloudflare is unreachable', function () {
    // Blocking signups during a Cloudflare outage is an acceptable cost;
    // locking every existing user out of their own books is not.
    enableTurnstile();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        Turnstile::RESPONSE_FIELD => 'a-token',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('login is blocked when cloudflare rejects the token', function () {
    enableTurnstile();
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        Turnstile::RESPONSE_FIELD => 'a-forged-token',
    ])->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);

    $this->assertGuest();
});

test('the password reset link request is challenged', function () {
    enableTurnstile();
    Http::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasErrors(Turnstile::RESPONSE_FIELD);
});

test('unprotected routes are not challenged', function () {
    enableTurnstile();
    Http::fake();

    // Rendering the form is a GET and carries no token; a Turnstile token is
    // single-use, so verifying anything but the submission would burn it.
    $this->get(route('register'))->assertOk();

    Http::assertNothingSent();
});

test('the widget renders only when turnstile is configured', function () {
    $this->get(route('register'))->assertDontSee('challenges.cloudflare.com');

    enableTurnstile();

    $this->get(route('register'))
        ->assertSee('challenges.cloudflare.com')
        ->assertSee('test-site-key', escape: false);
});
