<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Security\TrustedDeviceManager;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

/** Build a 2FA user whose secret is a real base32 key so OTP verification works. */
function twoFactorUser(): array
{
    $secret = (new Google2FA)->generateSecretKey();

    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    return [$user, $secret];
}

test('the step-up confirmation page renders for a 2FA user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('two-factor.reconfirm'))
        ->assertOk()
        ->assertSee('Confirm your identity');
});

test('a 2FA user must step up before reaching the security page', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertRedirect(route('two-factor.reconfirm'));
});

test('a 2FA user must step up before reaching company settings', function () {
    $company = Company::factory()->create();
    $user = User::factory()->withTwoFactor()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('companies.edit', $company))
        ->assertRedirect(route('two-factor.reconfirm'));
});

test('a recovery code clears the step-up gate', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    $this->get(route('security.edit'))->assertRedirect(route('two-factor.reconfirm'));

    $this->post(route('two-factor.reconfirm.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))->assertOk();
});

test('an authenticator code clears the step-up gate', function () {
    [$user, $secret] = twoFactorUser();

    $this->actingAs($user);

    $this->get(route('security.edit'))->assertRedirect(route('two-factor.reconfirm'));

    $this->post(route('two-factor.reconfirm.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))->assertOk();
});

test('the confirmation is reusable within the window and expires after it', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    $this->post(route('two-factor.reconfirm.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('security.edit'));

    // Still valid a few minutes later.
    $this->travel(10)->minutes();
    $this->get(route('security.edit'))->assertOk();

    // Expired past the 15-minute window.
    $this->travel(6)->minutes();
    $this->get(route('security.edit'))->assertRedirect(route('two-factor.reconfirm'));
});

test('a trusted device never bypasses the step-up gate', function () {
    $user = User::factory()->withTwoFactor()->create();

    $token = 'trusted-device-token';
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
    ]);

    $this->actingAs($user)
        ->withCookie(TrustedDeviceManager::COOKIE, $token)
        ->get(route('security.edit'))
        ->assertRedirect(route('two-factor.reconfirm'));
});

test('a user without 2FA falls back to password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('an invalid code is rejected and access stays blocked', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    $this->from(route('two-factor.reconfirm'))
        ->post(route('two-factor.reconfirm.store'), ['recovery_code' => 'definitely-wrong'])
        ->assertRedirect(route('two-factor.reconfirm'))
        ->assertSessionHasErrors('recovery_code');

    $this->get(route('security.edit'))->assertRedirect(route('two-factor.reconfirm'));
});

test('the step-up endpoint is rate limited', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    foreach (range(1, 5) as $ignored) {
        $this->from(route('two-factor.reconfirm'))
            ->post(route('two-factor.reconfirm.store'), ['recovery_code' => 'definitely-wrong']);
    }

    $this->post(route('two-factor.reconfirm.store'), ['recovery_code' => 'definitely-wrong'])
        ->assertStatus(429);
});
