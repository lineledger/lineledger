<?php

use App\Mcp\Tools\FinancialSummaryTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/**
 * Bind a company + API key the way AuthenticateApiKey would, so a tool's
 * handle() can be invoked directly. An empty abilities array = full access.
 *
 * @param  array<int, string>  $abilities
 */
function bindMcpCompany(Company $company, array $abilities = []): void
{
    // Reload from the database so DB-default columns (e.g. fiscal_year_start_month)
    // are populated, mirroring how AuthenticateApiKey loads the company in production.
    $company->refresh();

    ['key' => $key] = CompanyApiKey::mint($company, 'MCP test', null, $abilities);

    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);
}

it('summarizes receivables and payables for the bound company', function () {
    $company = Company::factory()->create();
    bindMcpCompany($company);

    Contact::factory()->create([
        'company_id' => $company->id,
        'is_customer' => true,
        'ar_balance_cents' => 50000,
    ]);

    Contact::factory()->create([
        'company_id' => $company->id,
        'is_vendor' => true,
        'ap_balance_cents' => 30000,
    ]);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect($response->isError())->toBeFalse();

    $text = (string) $response->content();

    expect($text)->toContain($company->name)
        ->toContain('500.00')   // total receivable
        ->toContain('300.00');  // total payable
});

it('refuses a financial summary when the key lacks accounting:read', function () {
    $company = Company::factory()->create();
    bindMcpCompany($company, ['sales:read']);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('accounting:read');
});

it('does not leak another company\'s receivables', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $companyB->id,
        'is_customer' => true,
        'ar_balance_cents' => 999900,
    ]);

    bindMcpCompany($companyA);

    $response = (new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']));

    expect((string) $response->content())->not->toContain('9,999.00');
});
