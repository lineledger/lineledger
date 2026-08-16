<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Create a company the given user is an Owner of, mirroring how the app seeds
 * membership (company_members pivot). CompanyFactory has no forUser() helper.
 */
function companyForMember(User $user, array $attributes = []): Company
{
    $company = Company::factory()->create($attributes);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    return $company;
}

/**
 * The OAuth connector is exposed per company at mcp/business/{company}. OAuth
 * (Passport, the `api` guard) authenticates the *user*; the `mcp.company`
 * middleware resolves the {company} slug from the URL and binds the tenant after
 * verifying membership. These tests pin that security boundary at the HTTP layer:
 * a user reaches only companies they belong to, which is what lets a multi-company
 * user add one connector per company and have each route to the right tenant.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Minimal MCP JSON-RPC body + headers. We only care about the auth/tenancy
 * boundary (the HTTP status from the middleware), not the MCP protocol result.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function mcpProbe(): array
{
    return [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        ['Accept' => 'application/json, text/event-stream'],
    ];
}

it('OAuthRouting: rejects an unauthenticated request with 401', function () {
    $company = Company::factory()->create();

    [$body, $headers] = mcpProbe();

    $this->postJson("/mcp/business/{$company->slug}", $body, $headers)
        ->assertStatus(401);
});

it('OAuthRouting: forbids an authenticated user from a company they do not belong to', function () {
    $user = User::factory()->create();
    $otherCompany = Company::factory()->create(); // user is NOT a member

    Passport::actingAs($user, ['mcp:use']);

    [$body, $headers] = mcpProbe();

    $response = $this->postJson("/mcp/business/{$otherCompany->slug}", $body, $headers);

    // Denied by our `mcp.company` middleware before it reaches the MCP server.
    $response->assertStatus(403);
    expect($response->getContent())->toContain('not a member of this company');
});

it('OAuthRouting: lets a member reach their company connector', function () {
    $user = User::factory()->create();
    $company = companyForMember($user);

    Passport::actingAs($user, ['mcp:use']);

    [$body, $headers] = mcpProbe();

    $response = $this->postJson("/mcp/business/{$company->slug}", $body, $headers);

    // Past auth + membership: the request reaches the MCP server itself and the
    // Business Q&A tools are listed — NOT a membership denial. That proves the
    // OAuth user was routed into their company's MCP server.
    expect($response->getContent())
        ->toContain('financial-summary-tool')
        ->not->toContain('not a member of this company');
});

it('OAuthRouting: routes a multi-company user to the company named in the URL', function () {
    $user = User::factory()->create();
    $acme = companyForMember($user, ['slug' => 'acme-co']);
    $globex = companyForMember($user, ['slug' => 'globex-co']);

    // A third company the user is NOT part of stays forbidden even while authed.
    $foreign = Company::factory()->create(['slug' => 'foreign-co']);

    Passport::actingAs($user, ['mcp:use']);

    [$body, $headers] = mcpProbe();

    // Both of the user's companies route through to the MCP server (tools listed).
    expect($this->postJson("/mcp/business/{$acme->slug}", $body, $headers)->getContent())
        ->toContain('financial-summary-tool');
    expect($this->postJson("/mcp/business/{$globex->slug}", $body, $headers)->getContent())
        ->toContain('financial-summary-tool');

    // The company they don't belong to is blocked by the membership check.
    $this->postJson("/mcp/business/{$foreign->slug}", $body, $headers)->assertStatus(403);
});
