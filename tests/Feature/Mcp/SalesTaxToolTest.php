<?php

declare(strict_types=1);

use App\Mcp\Tools\SalesTaxTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use Laravel\Mcp\Request;

it('SalesTax: summarizes collected, paid, and net owed per tax agency', function () {
    $company = Company::factory()->create();

    // The company setup seeds a chart of accounts; reuse one for the agency's
    // payable account rather than creating a colliding code.
    $payable = Account::query()->firstOrFail();

    TaxAgency::create([
        'company_id' => $company->id,
        'name' => 'CRA - GST/HST',
        'payable_account_id' => $payable->id,
        'is_active' => true,
    ]);

    bindMcpTenant($company);

    $response = (new SalesTaxTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain($company->name)
        ->and($content)->toContain('CRA - GST/HST')
        ->and($content)->toContain('net owed')
        ->and($content)->toContain('Total net owed:');
});

it('SalesTax: reports cleanly when no tax agencies are configured', function () {
    $company = Company::factory()->create();

    // Company setup seeds a default agency (e.g. Canada Revenue Agency); clear
    // them to exercise the genuine no-agencies path.
    TaxAgency::query()->delete();

    bindMcpTenant($company);

    $response = (new SalesTaxTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('No tax agencies');
});

it('SalesTax: denies access without the tax:read ability', function () {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['sales:read']);

    $response = (new SalesTaxTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('tax:read');
});
