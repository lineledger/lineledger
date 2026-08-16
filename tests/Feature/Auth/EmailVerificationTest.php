<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/**
 * UserFactory attaches a personal company on create. Verification now happens
 * *before* onboarding, so the interesting cases are users who have none yet —
 * strip it off. (Named distinctly from the onboarding suite's helper: a
 * duplicate global name only blows up on a full-suite run.)
 */
function userAwaitingOnboarding(bool $verified = false): User
{
    $user = User::factory()->when(! $verified, fn ($f) => $f->unverified())->create();

    $user->companies()->detach();
    $user->forceFill(['current_company_id' => null])->save();

    return $user->fresh();
}

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // Not `/dashboard` — that URI does not exist here (the real route is
    // `{company}/dashboard`), so Fortify's own response 404s. See
    // App\Http\Responses\VerifyEmailResponse.
    $response->assertRedirect("/{$user->currentCompany->slug}/dashboard?verified=1");
});

test('a user with no company lands on the onboarding wizard after verifying', function () {
    $user = userAwaitingOnboarding();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect('/welcome?verified=1');

    // EnsureUserHasCompany runs in the global web group, ahead of route
    // middleware. Unless verification.verify is exempt it redirects the user to
    // onboarding before the controller runs, and the emailed link silently
    // never verifies anything.
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('an unverified user cannot reach onboarding and is not caught in a redirect loop', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('welcome.create-company'))
        ->assertRedirect(route('verification.notice'));

    // The destination must actually render rather than bounce back to
    // onboarding — that pairing is the loop this guards against.
    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

test('an unverified user cannot reach the restore or company-picker screens', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('companies.restore'))
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($user)->get(route('companies.picker'))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user reaches onboarding normally', function () {
    $user = userAwaitingOnboarding(verified: true);

    $this->actingAs($user)->get(route('welcome.create-company'))->assertOk();
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect("/{$user->currentCompany->slug}/dashboard?verified=1");

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});
