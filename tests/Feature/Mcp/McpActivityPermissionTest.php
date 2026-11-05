<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\FinancialSummaryTool;
use App\Mcp\Tools\Write\ProposeExpenseTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/**
 * MCP tools gate on the same activity permissions as the web app — on an OAuth
 * connection as the signed-in member, and on an API-key connection as the
 * person who created the key.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('caps an API-key connection at what the key\'s creator may do', function () {
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $membership = $company->memberships()->create(['user_id' => $creator->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Banking->value]]);

    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Banking key', $creator->id);
    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);

    $denied = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));
    expect($denied->isError())->toBeTrue()
        ->and((string) $denied->content())->toContain('do not have access');

    $membership->update(['sections' => [Section::Banking->value, Section::Reports->value]]);

    expect((new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']))->isError())->toBeFalse();
});

it('leaves a key with no recorded creator to its scopes', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    expect((new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']))->isError())->toBeFalse();
});

it('asks for expenses — not the journal — before proposing an expense', function () {
    config(['mcp.write_enabled' => true]);
    $company = Company::factory()->create();
    $company->forceFill(['settings' => ['mcp' => ['agentic_writes' => true]]])->save();
    $user = User::factory()->create();
    $company->memberships()->create(['user_id' => $user->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Accounting->value]]);
    app()->instance('current_company', $company->refresh());
    Auth::guard('api')->setUser($user);

    $response = (new ProposeExpenseTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('Expenses');
});
