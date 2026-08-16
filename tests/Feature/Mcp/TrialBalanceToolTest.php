<?php

declare(strict_types=1);

use App\Mcp\Tools\TrialBalanceTool;
use App\Models\Company;
use Laravel\Mcp\Request;

it('TrialBalance: renders a balanced report for a freshly seeded company', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new TrialBalanceTool)->handle(new Request([]));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('Trial balance for')
        ->and($content)->toContain('Total debits:')
        ->and($content)->toContain('Total credits:')
        ->and($content)->toContain('in balance');
});

it('TrialBalance: accepts an explicit as-of date without error', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $response = (new TrialBalanceTool)->handle(new Request(['as_of' => '2026-06-15']));

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('Trial balance for');
});

it('TrialBalance: denies access without the accounting:read ability', function (): void {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['sales:read']);

    $response = (new TrialBalanceTool)->handle(new Request([]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('accounting:read');
});
