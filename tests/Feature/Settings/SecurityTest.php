<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable 2FA');
});

test('security settings page requires password confirmation when enabled', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('the passkey section renders an in-page password confirmation prompt', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee(__('Confirm your password'))
        ->assertSee(__('please confirm your password before registering a passkey'), false);
});

test('passkey registration is gated by password confirmation and the confirm endpoint unlocks it', function () {
    // UserFactory seeds the password "password".
    $user = User::factory()->create();

    $this->actingAs($user);

    // Not yet confirmed: the status endpoint reports false and the guarded passkey
    // registration route rejects the XHR with 423 — this is the "Password
    // confirmation required." case the modal is designed to resolve in-page.
    $this->getJson(route('password.confirmation'))
        ->assertOk()
        ->assertJson(['confirmed' => false]);

    $this->getJson(route('passkey.registration-options'))
        ->assertStatus(423);

    // A wrong password is rejected with a validation error under the `password` key.
    $this->postJson(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    // The correct password confirms (Fortify returns 201) and stamps the session.
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertStatus(201);

    // Now the status flips and the passkey registration route is reachable.
    $this->getJson(route('password.confirmation'))
        ->assertOk()
        ->assertJson(['confirmed' => true]);

    $this->getJson(route('passkey.registration-options'))
        ->assertOk();
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('the security page lists trusted devices and can forget them', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', 'a-token'),
        'expires_at' => now()->addDays(30),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->assertSet('twoFactorEnabled', true)
        ->assertCount('trustedDevices', 1)
        ->call('forgetTrustedDevices')
        ->assertCount('trustedDevices', 0);

    expect($user->twoFactorRememberedDevices()->count())->toBe(0);
});

test('disabling two-factor forgets trusted devices', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->twoFactorRememberedDevices()->create([
        'token_hash' => hash('sha256', 'a-token'),
        'expires_at' => now()->addDays(30),
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.security')->call('disable');

    expect($user->twoFactorRememberedDevices()->count())->toBe(0);
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
