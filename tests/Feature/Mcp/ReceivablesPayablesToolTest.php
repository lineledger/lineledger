<?php

use App\Mcp\Tools\AccountsPayableTool;
use App\Mcp\Tools\AccountsReceivableTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/**
 * @param  array<int, string>  $abilities
 */
function bindArApCompany(Company $company, array $abilities = []): void
{
    $company->refresh();

    ['key' => $key] = CompanyApiKey::mint($company, 'MCP test', null, $abilities);

    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);
}

it('lists customers who owe money and excludes zero balances', function () {
    $company = Company::factory()->create();
    bindArApCompany($company);

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Big Customer',
        'is_customer' => true,
        'ar_balance_cents' => 120000,
    ]);
    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Small Customer',
        'is_customer' => true,
        'ar_balance_cents' => 5000,
    ]);
    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Paid Up Customer',
        'is_customer' => true,
        'ar_balance_cents' => 0,
    ]);

    $response = (new AccountsReceivableTool)->handle(new Request([]));
    $text = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($text)->toContain('Big Customer');
    expect($text)->toContain('Small Customer');
    expect($text)->toContain('1,200.00');
    expect($text)->not->toContain('Paid Up Customer');
});

it('reports an empty receivables list cleanly', function () {
    $company = Company::factory()->create();
    bindArApCompany($company);

    $response = (new AccountsReceivableTool)->handle(new Request([]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('No customers currently owe');
});

it('lists vendors the company owes', function () {
    $company = Company::factory()->create();
    bindArApCompany($company);

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Supplies',
        'is_vendor' => true,
        'ap_balance_cents' => 75000,
    ]);

    $response = (new AccountsPayableTool)->handle(new Request([]));
    $text = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($text)->toContain('Acme Supplies');
    expect($text)->toContain('750.00');
});

it('gates receivables on sales:read and payables on purchases:read', function () {
    $company = Company::factory()->create();
    bindArApCompany($company, ['purchases:read']); // may read AP, not AR

    $ar = (new AccountsReceivableTool)->handle(new Request([]));
    expect($ar->isError())->toBeTrue();
    expect((string) $ar->content())->toContain('sales:read');

    $ap = (new AccountsPayableTool)->handle(new Request([]));
    expect($ap->isError())->toBeFalse();
});
