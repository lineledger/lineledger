<?php

use App\Models\User;
use App\Services\Security\AccessRevoker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('signs out a user who is disabled mid-session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('companies.picker'))
        ->assertOk();

    $user->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('companies.picker'))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
});

it('refuses a login with correct credentials once disabled', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);
    $user->forceFill(['disabled_at' => now()])->save();

    $this->post(route('login'), [
        'email' => 'locked@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('lets the user back in once re-enabled', function () {
    $user = User::factory()->create(['email' => 'freed@example.com']);
    $user->forceFill(['disabled_at' => now()])->save();

    $this->post(route('login'), ['email' => 'freed@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $user->forceFill(['disabled_at' => null])->save();

    $this->post(route('login'), ['email' => 'freed@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user->fresh());
});

it('leaves an enabled user alone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('companies.picker'))
        ->assertOk();
});

it('purges the sessions of a disabled user', function () {
    // The suite runs on the array session driver; AccessRevoker can only bulk
    // purge on the database driver, which is what production uses.
    config()->set('session.driver', 'database');

    $user = User::factory()->create();
    $before = $user->remember_token;

    DB::table('sessions')->insert([
        'id' => 'session-under-test',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'phpunit',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    app(AccessRevoker::class)->revokeForAccountDisabled($user);

    $this->assertDatabaseMissing('sessions', ['id' => 'session-under-test']);

    // The remember-me cookie must not be able to re-establish the session.
    expect($user->fresh()->remember_token)->not->toBe($before);
});
