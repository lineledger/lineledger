<?php

use App\Models\User;
use App\Services\Security\TrustedDeviceManager;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('the challenge page shows the remember-device option', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('Remember this device');
});

test('ticking remember-device on the challenge trusts the device and sets a cookie', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Logging in records the challenged user in the session and bounces to 2FA.
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $response = $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
        'remember_device' => '1',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertCookie(TrustedDeviceManager::COOKIE);

    expect($user->twoFactorRememberedDevices()->count())->toBe(1);
});

test('a challenge without remember-device leaves no trusted device', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $response = $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertCookieMissing(TrustedDeviceManager::COOKIE);

    expect($user->twoFactorRememberedDevices()->count())->toBe(0);
});

test('a trusted device skips the two-factor challenge at login', function () {
    $user = User::factory()->withTwoFactor()->create();

    $token = 'trusted-device-token';
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->withCookie(TrustedDeviceManager::COOKIE, $token)
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    // Logged straight into the app rather than bounced to the challenge.
    $this->assertAuthenticatedAs($user);
    expect($response->headers->get('Location'))->not->toBe(route('two-factor.login'));
});

test('an expired trusted device still requires the challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $token = 'expired-device-token';
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subDay(),
    ]);

    $this->withCookie(TrustedDeviceManager::COOKIE, $token)
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('forgetting trusted devices removes the rows', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', 'some-token'),
        'expires_at' => now()->addDays(30),
    ]);

    app(TrustedDeviceManager::class)->forgetAllDevices($user);

    expect($user->twoFactorRememberedDevices()->count())->toBe(0);
});
