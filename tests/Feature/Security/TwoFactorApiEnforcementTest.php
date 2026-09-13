<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use Laravel\Fortify\Features;
use Laravel\Passport\Passport;

/**
 * Finding #3 (2026-08-18 security review): EnforceTwoFactor only ran on the
 * {company}-prefixed web route group, so a company that mandated 2FA for its
 * owners/admins got that protection only in the interactive UI — a
 * CompanyApiKey or MCP OAuth token worked with no 2FA check at all.
 * EnforceTwoFactorForApi (routes/api.php, routes/ai.php) closes that: same
 * decision as the web middleware (TwoFactorRequirement::isUnmet), but a hard
 * 403 instead of a redirect, since there's no page to bounce a script to.
 */
beforeEach(function () {
    if (! Features::enabled(Features::twoFactorAuthentication())) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }
});

function companyMember(Company $company, CompanyRole $role, bool $withTwoFactor = false): User
{
    $user = $withTwoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
    $company->members()->attach($user, ['role' => $role->value]);

    return $user;
}

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('rejects an API key minted by an un-enrolled admin when the company requires 2FA', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = companyMember($company, CompanyRole::Owner);
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Owner key', $owner->id);

    $response = $this->getJson('/api/v1/accounts', ['Authorization' => "Bearer {$plain}"]);

    $response->assertStatus(403);
    expect($response->getContent())->toContain('two-factor');
});

it('allows an API key minted by an admin who has enrolled in 2FA', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = companyMember($company, CompanyRole::Owner, withTwoFactor: true);
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Owner key', $owner->id);

    $this->getJson('/api/v1/accounts', ['Authorization' => "Bearer {$plain}"])
        ->assertOk();
});

it('allows an API key minted by a non-admin regardless of 2FA enrollment', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $accountant = companyMember($company, CompanyRole::Accountant);
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Accountant key', $accountant->id);

    $this->getJson('/api/v1/accounts', ['Authorization' => "Bearer {$plain}"])
        ->assertOk();
});

it('does not enforce 2FA on API keys when the company has not opted in', function () {
    $company = Company::factory()->create(['require_two_factor' => false]);
    $owner = companyMember($company, CompanyRole::Owner);
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Owner key', $owner->id);

    $this->getJson('/api/v1/accounts', ['Authorization' => "Bearer {$plain}"])
        ->assertOk();
});

it('rejects an MCP request over an API key minted by an un-enrolled admin', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = companyMember($company, CompanyRole::Owner);
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Owner key', $owner->id);

    $response = $this->postJson(
        '/mcp/business',
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json, text/event-stream'],
    );

    $response->assertStatus(403);
    expect($response->getContent())->toContain('two-factor');
});

it('rejects an MCP OAuth request from an un-enrolled admin', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = companyMember($company, CompanyRole::Owner);

    Passport::actingAs($owner, ['mcp:use']);

    $response = $this->postJson(
        "/mcp/business/{$company->slug}",
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Accept' => 'application/json, text/event-stream'],
    );

    $response->assertStatus(403);
    expect($response->getContent())->toContain('two-factor');
});

it('allows an MCP OAuth request from an admin who has enrolled in 2FA', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = companyMember($company, CompanyRole::Owner, withTwoFactor: true);

    Passport::actingAs($owner, ['mcp:use']);

    $response = $this->postJson(
        "/mcp/business/{$company->slug}",
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Accept' => 'application/json, text/event-stream'],
    );

    expect($response->getContent())->not->toContain('two-factor');
});
