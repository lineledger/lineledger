<?php

declare(strict_types=1);

use App\Mcp\Tools\SalesReportTool;
use App\Models\Company;
use Laravel\Mcp\Request;

it('SalesReport: renders a sales-by-customer report without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'customer',
    ]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('Sales by customer');
});

it('SalesReport: renders a sales-by-item report without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new SalesReportTool)->handle(new Request([
        'period' => 'this_year',
        'group_by' => 'item',
    ]));

    expect($response->isError())->toBeFalse();
});

it('SalesReport: denies access without the sales:read ability', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['purchases:read']);

    $response = (new SalesReportTool)->handle(new Request(['period' => 'this_year']));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('sales:read');
});
