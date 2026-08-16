<?php

declare(strict_types=1);

use App\Mcp\Tools\BalanceSheetTool;
use App\Models\Company;
use Laravel\Mcp\Request;

it('BalanceSheet: renders assets, liabilities and equity and reports balance status', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new BalanceSheetTool)->handle(new Request(['as_of' => '2026-06-15']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('Total Assets:')
        ->and($content)->toContain('Total Liabilities:')
        ->and($content)->toContain('Total Equity:')
        ->and($content)->toContain('Liabilities + Equity:')
        ->and($content)->toContain('Assets = Liabilities + Equity');
});

it('BalanceSheet: denies access without the accounting:read ability', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['sales:read']);

    $response = (new BalanceSheetTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('accounting:read');
});
