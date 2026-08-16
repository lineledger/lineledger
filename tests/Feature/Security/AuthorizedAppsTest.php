<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The self-service "Authorized applications" screen (Settings → Security) lists
 * the signed-in user's live OAuth connections — chiefly MCP clients such as
 * Claude — and lets them revoke one without waiting for it to expire or needing
 * an admin to remove them from the company.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Seed one authorized app for a user: an OAuth client plus a live access token
 * and its paired refresh token, mirroring what a completed connect flow leaves.
 *
 * @return array{client_id: string, token_id: string, refresh_id: string}
 */
function seedOAuthApp(int $userId, string $name): array
{
    $clientId = (string) Str::uuid();
    DB::table('oauth_clients')->insert([
        'id' => $clientId,
        'name' => $name,
        'secret' => null,
        'provider' => 'users',
        'redirect_uris' => '[]',
        'grant_types' => '["authorization_code"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tokenId = Str::random(80);
    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $userId,
        'client_id' => $clientId,
        'name' => $name,
        'scopes' => '[]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDays(15),
    ]);

    $refreshId = Str::random(80);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshId,
        'access_token_id' => $tokenId,
        'revoked' => false,
        'expires_at' => now()->addDays(30),
    ]);

    return ['client_id' => $clientId, 'token_id' => $tokenId, 'refresh_id' => $refreshId];
}

it('lists the current user active OAuth connections', function () {
    seedOAuthApp($this->user->id, 'Claude');

    Livewire::test('pages::settings.authorized-apps')
        ->assertOk()
        ->assertSee('Claude');
});

it('hides revoked, expired, and other users tokens', function () {
    $revoked = seedOAuthApp($this->user->id, 'Revoked app');
    DB::table('oauth_access_tokens')->where('id', $revoked['token_id'])->update(['revoked' => true]);

    $expired = seedOAuthApp($this->user->id, 'Expired app');
    DB::table('oauth_access_tokens')->where('id', $expired['token_id'])->update(['expires_at' => now()->subDay()]);

    seedOAuthApp(User::factory()->create()->id, 'Bystander app');

    Livewire::test('pages::settings.authorized-apps')
        ->assertOk()
        ->assertDontSee('Revoked app')
        ->assertDontSee('Expired app')
        ->assertDontSee('Bystander app')
        ->assertSee('No connected applications');
});

it('revokes one app and its refresh token, leaves others, and logs the event', function () {
    $claude = seedOAuthApp($this->user->id, 'Claude');
    $zapier = seedOAuthApp($this->user->id, 'Zapier');

    Livewire::test('pages::settings.authorized-apps')
        ->call('revoke', $claude['client_id'])
        ->assertHasNoErrors();

    // The revoked app's access token and its paired refresh token are dead...
    expect((int) DB::table('oauth_access_tokens')->where('id', $claude['token_id'])->value('revoked'))->toBe(1)
        ->and((int) DB::table('oauth_refresh_tokens')->where('id', $claude['refresh_id'])->value('revoked'))->toBe(1)
        // ...while the untouched connection keeps working.
        ->and((int) DB::table('oauth_access_tokens')->where('id', $zapier['token_id'])->value('revoked'))->toBe(0)
        ->and((int) DB::table('oauth_refresh_tokens')->where('id', $zapier['refresh_id'])->value('revoked'))->toBe(0);

    $log = SecurityLog::query()->where('event', SecurityEvent::McpConnectionRevoked->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->metadata['client_id'])->toBe($claude['client_id'])
        ->and($log->metadata['client_name'])->toBe('Claude');
});

it('does not let a user revoke another user tokens', function () {
    $mine = seedOAuthApp($this->user->id, 'Claude');
    $theirs = seedOAuthApp(User::factory()->create()->id, 'Someone else');

    // Even if a foreign client id is passed, only the acting user's tokens for
    // that client are considered — the other user's stay active.
    Livewire::test('pages::settings.authorized-apps')
        ->call('revoke', $theirs['client_id'])
        ->assertHasNoErrors();

    expect((int) DB::table('oauth_access_tokens')->where('id', $theirs['token_id'])->value('revoked'))->toBe(0)
        ->and((int) DB::table('oauth_access_tokens')->where('id', $mine['token_id'])->value('revoked'))->toBe(0);
});
