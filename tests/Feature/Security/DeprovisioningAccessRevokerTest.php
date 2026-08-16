<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use App\Services\Security\AccessRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * When a member is removed from a company or downgraded to a lower role, the
 * access they held under the old level must be torn down, not left to expire
 * (SOC 2 CC6.2 deprovisioning / CC6.3 role change): server sessions, the
 * company API keys they minted, and — on full removal — their OAuth tokens.
 */
beforeEach(function () {
    config(['session.driver' => 'database']);

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function seedAccess(int $userId, Company $company): array
{
    $sessionId = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $userId,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Test',
        'payload' => 'x',
        'last_activity' => 1_700_000_000,
    ]);

    $key = CompanyApiKey::mint($company, 'Member key', $userId)['key'];

    $tokenId = Str::random(80);
    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $userId,
        'client_id' => (string) Str::uuid(),
        'name' => 'mcp',
        'scopes' => '[]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['session_id' => $sessionId, 'key_id' => $key->id, 'token_id' => $tokenId];
}

it('revokes sessions, company API keys, and OAuth tokens on removal', function () {
    $member = User::factory()->create();
    $ids = seedAccess($member->id, $this->company);

    app(AccessRevoker::class)->revokeForRemoval($member, $this->company);

    expect(DB::table('sessions')->where('id', $ids['session_id'])->exists())->toBeFalse();
    expect(CompanyApiKey::withoutGlobalScopes()->find($ids['key_id'])->revoked_at)->not->toBeNull();
    expect((int) DB::table('oauth_access_tokens')->where('id', $ids['token_id'])->value('revoked'))->toBe(1);
});

it('revokes paired refresh tokens and cycles the remember token on removal', function () {
    $member = User::factory()->create(['remember_token' => 'old-remember-token']);
    $ids = seedAccess($member->id, $this->company);

    $refreshId = Str::random(80);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshId,
        'access_token_id' => $ids['token_id'],
        'revoked' => false,
        'expires_at' => now()->addDays(30),
    ]);

    app(AccessRevoker::class)->revokeForRemoval($member, $this->company);

    // The refresh token can no longer mint a fresh access token...
    expect((int) DB::table('oauth_refresh_tokens')->where('id', $refreshId)->value('revoked'))->toBe(1)
        // ...and a stale "remember me" cookie can no longer rebuild a session.
        ->and($member->fresh()->remember_token)->not->toBe('old-remember-token');
});

it('drops sessions and API keys but keeps OAuth tokens on downgrade', function () {
    $member = User::factory()->create();
    $ids = seedAccess($member->id, $this->company);

    app(AccessRevoker::class)->revokeForDowngrade($member, $this->company);

    expect(DB::table('sessions')->where('id', $ids['session_id'])->exists())->toBeFalse();
    expect(CompanyApiKey::withoutGlobalScopes()->find($ids['key_id'])->revoked_at)->not->toBeNull();
    // Membership is retained, so OAuth tokens (re-checked per request) survive.
    expect((int) DB::table('oauth_access_tokens')->where('id', $ids['token_id'])->value('revoked'))->toBe(0);
});

it('does not touch another company API keys or another user sessions', function () {
    $member = User::factory()->create();
    $bystander = User::factory()->create();
    $otherCompany = Company::factory()->create();

    $ids = seedAccess($member->id, $this->company);
    $bystanderSession = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $bystanderSession,
        'user_id' => $bystander->id,
        'ip_address' => '203.0.113.8',
        'user_agent' => 'Test',
        'payload' => 'x',
        'last_activity' => 1_700_000_000,
    ]);
    // Mint under the other company's context so BelongsToCompany's creating
    // hook stamps the correct company_id (it overrides with current_company).
    app()->instance('current_company', $otherCompany);
    $otherKey = CompanyApiKey::mint($otherCompany, 'Other co key', $member->id)['key'];
    app()->instance('current_company', $this->company);

    app(AccessRevoker::class)->revokeForRemoval($member, $this->company);

    expect(DB::table('sessions')->where('id', $bystanderSession)->exists())->toBeTrue();
    expect(CompanyApiKey::withoutGlobalScopes()->find($otherKey->id)->revoked_at)->toBeNull();
});

it('removeMember wires the revoker so the removed member is deprovisioned', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $ids = seedAccess($member->id, $this->company);

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $this->company, 'memberId' => $member->id])
        ->call('removeMember')
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', $ids['session_id'])->exists())->toBeFalse();
    expect(CompanyApiKey::withoutGlobalScopes()->find($ids['key_id'])->revoked_at)->not->toBeNull();
    expect((int) DB::table('oauth_access_tokens')->where('id', $ids['token_id'])->value('revoked'))->toBe(1);
});

it('updateMember deprovisions on a role downgrade but not a promotion', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($member, ['role' => CompanyRole::Admin->value]);

    $ids = seedAccess($member->id, $this->company);

    $this->actingAs($owner);

    // Downgrade Admin -> Accountant: sessions + API keys revoked.
    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->call('updateMember', $member->id, CompanyRole::Accountant->value)
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', $ids['session_id'])->exists())->toBeFalse();
    expect(CompanyApiKey::withoutGlobalScopes()->find($ids['key_id'])->revoked_at)->not->toBeNull();

    // Re-seed and promote Accountant -> Admin: nothing is revoked.
    $ids2 = seedAccess($member->id, $this->company);
    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->call('updateMember', $member->id, CompanyRole::Admin->value)
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', $ids2['session_id'])->exists())->toBeTrue();
    expect(CompanyApiKey::withoutGlobalScopes()->find($ids2['key_id'])->revoked_at)->toBeNull();
});
