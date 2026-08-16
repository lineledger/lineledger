<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\FinancialSummaryTool;
use App\Mcp\Tools\FindContactTool;
use App\Mcp\Tools\InventoryStatusTool;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/**
 * Pins the OAuth-path authorization boundary (security fix): bare company
 * membership is NOT enough to invoke a tool — the member must also be granted the
 * Section the tool maps to, mirroring the web app's EnsureSectionAccess. On the
 * API-key path (no OAuth user) section access does not apply; ability scopes gate
 * instead.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Bind the tenant and authenticate the given user on the OAuth (`api`) guard the
 * way BindMcpCompany + auth:api would, so a tool's handle() exercises the
 * OAuth-path authorization. No API key is bound.
 *
 * @param  array<int, string>  $sections  section values for a Custom-role member; null = Owner (full access)
 */
function actAsOauthMember(User $user, Company $company, ?array $sections = null): void
{
    $company->refresh();

    // Use the Membership model (memberships()->create) so the `sections` array
    // cast applies — pivot attach() would store a raw/double-encoded value.
    $company->memberships()->create($sections === null
        ? ['user_id' => $user->id, 'role' => CompanyRole::Owner]
        : ['user_id' => $user->id, 'role' => CompanyRole::Custom, 'sections' => $sections]
    );

    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);
}

it('OAuthSection: denies a financial tool to a member without the Reports section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    actAsOauthMember($user, $company, [Section::Banking->value]);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('do not have access');
});

it('OAuthSection: allows a financial tool to a member granted the Reports section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    actAsOauthMember($user, $company, [Section::Reports->value]);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect($response->isError())->toBeFalse();
});

it('OAuthSection: denies inventory status to a member without the Inventory section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    actAsOauthMember($user, $company, [Section::Reports->value]); // Reports, not Inventory

    $response = (new InventoryStatusTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('do not have access');
});

it('OAuthSection: a contact lookup is allowed with EITHER Sales or Purchases', function () {
    $company = Company::factory()->create();

    $customersOnly = User::factory()->create();
    actAsOauthMember($customersOnly, $company, [Section::Customers->value]);
    expect((new FindContactTool)->handle(new Request(['name' => 'nobody']))->isError())->toBeFalse();

    app()->forgetInstance('current_company');

    $vendorsOnly = User::factory()->create();
    actAsOauthMember($vendorsOnly, $company, [Section::Vendors->value]);
    expect((new FindContactTool)->handle(new Request(['name' => 'nobody']))->isError())->toBeFalse();

    app()->forgetInstance('current_company');

    // A member with neither Sales nor Purchases is denied.
    $bankingOnly = User::factory()->create();
    actAsOauthMember($bankingOnly, $company, [Section::Banking->value]);
    expect((new FindContactTool)->handle(new Request(['name' => 'nobody']))->isError())->toBeTrue();
});

it('OAuthSection: an Owner reaches the financial and inventory tools', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    actAsOauthMember($user, $company); // Owner = full section access

    expect((new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']))->isError())->toBeFalse();
    expect((new InventoryStatusTool)->handle(new Request([]))->isError())->toBeFalse();
});

it('OAuthSection: the API-key path applies no section gate (ability scopes govern instead)', function () {
    $company = Company::factory()->create();

    // bindMcpTenant binds current_company + a full-access API key, and does NOT
    // authenticate an OAuth user — so requireSection() must be a no-op here.
    bindMcpTenant($company);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect($response->isError())->toBeFalse();
});

it('OAuthSection: redirect_domains is not a wildcard', function () {
    expect(config('mcp.redirect_domains'))->not->toContain('*')
        ->and(config('mcp.redirect_domains'))->not->toBeEmpty();
});
