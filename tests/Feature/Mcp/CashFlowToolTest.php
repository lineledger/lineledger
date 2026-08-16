<?php

use App\Mcp\Tools\CashFlowTool;
use App\Models\Company;
use Laravel\Mcp\Request;

it('CashFlow: renders operating, investing and financing sections with net change in cash', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new CashFlowTool)->handle(new Request([
        'period' => 'this_year',
    ]));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('Statement of Cash Flows')
        ->and($content)->toContain('Operating activities')
        ->and($content)->toContain('Investing activities')
        ->and($content)->toContain('Financing activities')
        ->and($content)->toContain('Net change in cash')
        ->and($content)->toContain('Cash at beginning of period')
        ->and($content)->toContain('Cash at end of period')
        ->and($content)->toMatch('/\$[\d,]+\.\d{2}/');
});

it('CashFlow: accepts explicit start and end dates without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new CashFlowTool)->handle(new Request([
        'start' => '2026-01-01',
        'end' => '2026-03-31',
    ]));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('Net change in cash');
});

it('CashFlow: is denied without the accounting:read ability', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['sales:read']);

    $response = (new CashFlowTool)->handle(new Request([
        'period' => 'this_year',
    ]));

    expect($response->isError())->toBeTrue();
});
