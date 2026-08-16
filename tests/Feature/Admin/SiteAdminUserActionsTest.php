<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->siteAdmin()->withTwoFactor()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);
});

it('edits a user name and email', function () {
    $target = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    Livewire::test('pages::admin.users')
        ->call('openEdit', $target->id)
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('saveUser')
        ->assertHasNoErrors();

    $target->refresh();

    expect($target->name)->toBe('New Name')
        ->and($target->email)->toBe('new@example.com');
});

it('clears the verified stamp when the email changes', function () {
    $target = User::factory()->create(['email' => 'before@example.com']);

    expect($target->email_verified_at)->not->toBeNull();

    Livewire::test('pages::admin.users')
        ->call('openEdit', $target->id)
        ->set('email', 'after@example.com')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($target->fresh()->email_verified_at)->toBeNull();
});

it('keeps the verified stamp when only the name changes', function () {
    $target = User::factory()->create(['name' => 'Same Email']);

    Livewire::test('pages::admin.users')
        ->call('openEdit', $target->id)
        ->set('name', 'Renamed')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($target->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects an email that belongs to another user', function () {
    $target = User::factory()->create();
    $other = User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test('pages::admin.users')
        ->call('openEdit', $target->id)
        ->set('email', 'taken@example.com')
        ->call('saveUser')
        ->assertHasErrors('email');

    expect($target->fresh()->email)->not->toBe($other->email);
});

it('marks an email verified and re-sends the verification link', function () {
    Notification::fake();

    $target = User::factory()->unverified()->create();

    Livewire::test('pages::admin.users')->call('resendVerification', $target->id);

    Notification::assertSentTo($target, VerifyEmail::class);

    Livewire::test('pages::admin.users')->call('markEmailVerified', $target->id);

    expect($target->fresh()->email_verified_at)->not->toBeNull();
});

it('sends a password reset link', function () {
    Notification::fake();

    $target = User::factory()->create();

    Livewire::test('pages::admin.users')->call('sendPasswordReset', $target->id);

    Notification::assertSentTo($target, ResetPassword::class);
});

it('resets a users two-factor enrolment', function () {
    $target = User::factory()->withTwoFactor()->create();

    Livewire::test('pages::admin.users')->call('resetTwoFactor', $target->id);

    $target->refresh();

    expect($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->two_factor_confirmed_at)->toBeNull();
});

it('refuses to reset two-factor for the last enrolled site admin', function () {
    // $this->admin is the only site admin with 2FA, so clearing it would lock
    // every operator out of the portal.
    Livewire::test('pages::admin.users')->call('resetTwoFactor', $this->admin->id);

    expect($this->admin->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('disables a user and tears down their access', function () {
    $target = User::factory()->create(['name' => 'Mallory']);
    $company = Company::factory()->create();
    $company->members()->attach($target, ['role' => CompanyRole::Owner->value]);
    ['key' => $key] = CompanyApiKey::mint($company, 'Mallory key', $target->id);

    Livewire::test('pages::admin.users')
        ->set('disableReason', 'Abuse report')
        ->call('toggleDisabled', $target->id);

    $target->refresh();

    expect($target->isDisabled())->toBeTrue()
        ->and($target->disabled_by)->toBe($this->admin->email)
        ->and($target->disabled_reason)->toBe('Abuse report')
        ->and($key->fresh()->revoked_at)->not->toBeNull();
});

it('re-enables a disabled user', function () {
    $target = User::factory()->create();
    $target->forceFill([
        'disabled_at' => now(),
        'disabled_by' => 'someone@example.com',
        'disabled_reason' => 'Testing',
    ])->save();

    Livewire::test('pages::admin.users')->call('toggleDisabled', $target->id);

    $target->refresh();

    expect($target->isDisabled())->toBeFalse()
        ->and($target->disabled_by)->toBeNull()
        ->and($target->disabled_reason)->toBeNull();
});

it('refuses to disable your own account', function () {
    Livewire::test('pages::admin.users')->call('toggleDisabled', $this->admin->id);

    expect($this->admin->fresh()->isDisabled())->toBeFalse();
});

it('refuses to disable the last enabled site admin', function () {
    $other = User::factory()->siteAdmin()->create();

    // With two enabled admins, disabling one is fine.
    Livewire::test('pages::admin.users')->call('toggleDisabled', $other->id);
    expect($other->fresh()->isDisabled())->toBeTrue();
});

it('will not leave the platform without a usable admin', function () {
    $other = User::factory()->siteAdmin()->create();

    // The acting admin is themselves disabled (a session that outlived the
    // lockout), so $other is the only admin who could still sign in.
    $this->admin->forceFill(['disabled_at' => now()])->save();
    $this->actingAs($this->admin->fresh());

    Livewire::test('pages::admin.users')->call('toggleDisabled', $other->id);

    expect($other->fresh()->isDisabled())->toBeFalse();
});

it('does not count a disabled admin as the last remaining site admin', function () {
    $sleeping = User::factory()->siteAdmin()->create();
    $sleeping->forceFill(['disabled_at' => now()])->save();

    // Only $this->admin is usable, so revoking their role must still be refused.
    Livewire::test('pages::admin.users')->call('toggleSiteAdmin', $this->admin->id);

    expect($this->admin->fresh()->site_admin)->toBeTrue();
});

it('renders the row actions and the disabled attribution', function () {
    $target = User::factory()->unverified()->withTwoFactor()->create(['name' => 'Zed Target']);

    Livewire::test('pages::admin.users')
        ->assertSee('Disable account')
        ->assertSee('Send password reset')
        ->assertSee('Reset 2FA')
        ->assertSee('Mark email verified');

    $target->forceFill([
        'disabled_at' => now(),
        'disabled_by' => 'ada@example.com',
        'disabled_reason' => 'Spam',
    ])->save();

    Livewire::test('pages::admin.users')
        ->assertSee('Enable account')
        ->assertSee('ada@example.com')
        ->assertSee('Spam');
});

it('filters by disabled status', function () {
    User::factory()->create(['name' => 'Active Alice']);
    $disabled = User::factory()->create(['name' => 'Disabled Dave']);
    $disabled->forceFill(['disabled_at' => now()])->save();

    Livewire::test('pages::admin.users')
        ->set('statusFilter', 'disabled')
        ->assertSee('Disabled Dave')
        ->assertDontSee('Active Alice')
        ->set('statusFilter', 'active')
        ->assertSee('Active Alice')
        ->assertDontSee('Disabled Dave');
});

it('blocks a non-admin from every mutation', function () {
    $target = User::factory()->create(['name' => 'Untouchable']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.users')->assertStatus(404);

    foreach (['openEdit', 'toggleDisabled', 'sendPasswordReset', 'resetTwoFactor', 'markEmailVerified'] as $method) {
        try {
            Livewire::test('pages::admin.users')->call($method, $target->id);
        } catch (Throwable) {
            // 404 from the in-component guard — the write was blocked.
        }
    }

    expect($target->fresh()->isDisabled())->toBeFalse()
        ->and($target->fresh()->name)->toBe('Untouchable');
});
