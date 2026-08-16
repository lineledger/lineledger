<?php

use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('enabling 2FA through the setup modal surfaces recovery codes', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->call('startTwoFactorSetup');

    $secret = decrypt($user->fresh()->two_factor_secret);
    $otp = (new Google2FA)->getCurrentOtp($secret);

    $component->set('code', $otp)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSet('setupComplete', true);

    expect($component->get('recoveryCodes'))->not->toBeEmpty();
});
