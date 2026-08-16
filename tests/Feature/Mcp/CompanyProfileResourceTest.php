<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Enums\Section;
use App\Mcp\Resources\CompanyProfileResource;
use App\Mcp\Tools\CompanyProfileTool;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/**
 * The company-profile resource (and its companion tool) surfaces the fiscal-year
 * dates, organization type, and CRA filing profile. Authorization mirrors the web
 * app: the OAuth path requires the Settings section; the API-key path is governed
 * by abilities and is a no-op for section checks.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    Auth::forgetGuards();
});

it('CompanyProfile: resource reports fiscal-year window, org type, and CRA filing', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
        'fiscal_year_start_month' => 1,
    ]);
    bindMcpTenant($company);

    $response = (new CompanyProfileResource)->handle(new Request([]));
    $content = (string) $response->content();

    expect($response->isError())->toBeFalse()
        ->and($content)->toContain($company->name)
        ->and($content)->toContain('Fiscal year')
        ->and($content)->toContain('Current fiscal year:')
        ->and($content)->toContain('Corporation')
        ->and($content)->toContain('T2 Corporation Income Tax Return');
});

it('CompanyProfile: companion tool returns the same profile text', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    bindMcpTenant($company);

    $resourceText = (string) (new CompanyProfileResource)->handle(new Request([]))->content();
    $toolText = (string) (new CompanyProfileTool)->handle(new Request([]))->content();

    expect($toolText)->toBe($resourceText);
});

it('CompanyProfile: a US company shows no CRA filing', function () {
    $company = Company::factory()->forCountry(Country::UnitedStates)->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    bindMcpTenant($company);

    $content = (string) (new CompanyProfileTool)->handle(new Request([]))->content();

    expect($content)->toContain('CRA filing: none');
});

it('CompanyProfile: denies the resource to an OAuth member without the Settings section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    $response = (new CompanyProfileResource)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('do not have access');
});

it('CompanyProfile: allows the resource to an OAuth member granted the Settings section', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Settings->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    expect((new CompanyProfileResource)->handle(new Request([]))->isError())->toBeFalse();
});

it('CompanyProfile: denies the resource when the API key lacks accounting:read', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    $response = (new CompanyProfileResource)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('accounting:read');
});
