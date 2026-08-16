<?php

use App\Mcp\Tools\ProfitAndLossTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/**
 * @param  array<int, string>  $abilities
 */
function bindPnlCompany(Company $company, array $abilities = []): void
{
    $company->refresh();

    ['key' => $key] = CompanyApiKey::mint($company, 'MCP test', null, $abilities);

    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);
}

it('renders an income statement skeleton with no posted activity', function () {
    $company = Company::factory()->create();
    bindPnlCompany($company);

    $response = (new ProfitAndLossTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeFalse();

    $text = (string) $response->content();

    expect($text)->toContain($company->name)
        ->toContain('Income')
        ->toContain('Expenses')
        ->toContain('Net profit: ');
});

it('refuses a profit and loss statement when the key lacks accounting:read', function () {
    $company = Company::factory()->create();
    bindPnlCompany($company, ['sales:read']);

    $response = (new ProfitAndLossTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('accounting:read');
});
