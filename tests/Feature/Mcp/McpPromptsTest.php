<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Enums\Section;
use App\Mcp\Prompts\ArCollectionsPrompt;
use App\Mcp\Prompts\MonthEndCloseReviewPrompt;
use App\Mcp\Prompts\SalesTaxFilingPrepPrompt;
use App\Mcp\Prompts\YearEndTaxPrepChecklistPrompt;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    Auth::forgetGuards();
});

it('McpPrompts: month-end close review steers the model through the close tools', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $content = (string) (new MonthEndCloseReviewPrompt)->handle(new Request(['period' => 'last_month']))->content();

    expect($content)->toContain($company->name)
        ->toContain('last_month')
        ->toContain('profit-and-loss')
        ->toContain('balance-sheet');
});

it('McpPrompts: AR collections prompt references the receivables and contact tools', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $content = (string) (new ArCollectionsPrompt)->handle(new Request([]))->content();

    expect($content)->toContain('accounts-receivable')
        ->toContain('find-contact');
});

it('McpPrompts: sales-tax filing prep uses the jurisdiction tax label', function () {
    $company = Company::factory()->create(); // Canadian by default
    bindMcpTenant($company);

    $content = (string) (new SalesTaxFilingPrepPrompt)->handle(new Request(['period' => 'this_quarter']))->content();

    expect($content)->toContain('GST/HST')
        ->toContain('this_quarter')
        ->toContain('lineledger://tax/codes');
});

it('McpPrompts: year-end checklist is entity-aware for a corporation', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    bindMcpTenant($company);

    $content = (string) (new YearEndTaxPrepChecklistPrompt)->handle(new Request([]))->content();

    expect($content)->toContain('T2 Corporation Income Tax Return');
});

it('McpPrompts: year-end checklist is entity-aware for a sole proprietor', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::SoleProprietorship->value,
    ]);
    bindMcpTenant($company);

    $content = (string) (new YearEndTaxPrepChecklistPrompt)->handle(new Request([]))->content();

    expect($content)->toContain('T2125 Statement of Business or Professional Activities');
});

it('McpPrompts: year-end checklist denies an OAuth member without the Reports section', function () {
    $company = Company::factory()->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]);
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    $response = (new YearEndTaxPrepChecklistPrompt)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('do not have access');
});
