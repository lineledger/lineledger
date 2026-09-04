<?php

use App\Mcp\Tools\FindContactTool;
use App\Models\Company;
use App\Models\Contact;
use Laravel\Mcp\Request;

it('FindContact: reports balances for a single matched contact', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Plumbing',
        'company_name' => 'Acme Plumbing Ltd',
        'is_customer' => true,
        'ar_balance_cents' => 125000,
    ]);

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Zenith Roofing',
        'company_name' => 'Zenith Roofing Inc',
        'is_customer' => true,
        'ar_balance_cents' => 5000,
    ]);

    bindMcpTenant($company);

    $response = (new FindContactTool)->handle(new Request(['name' => 'Acme']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();
    expect($content)->toContain('Acme Plumbing');
    expect($content)->toContain('AR balance');
    expect($content)->toContain('Customer');
    expect($content)->not->toContain('Zenith Roofing');
});

it('FindContact: asks the user to be specific when multiple contacts match', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Northern Supply Co',
        'is_vendor' => true,
        'ap_balance_cents' => 30000,
    ]);

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Northern Lights Cafe',
        'is_customer' => true,
        'ar_balance_cents' => 7500,
    ]);

    bindMcpTenant($company);

    $response = (new FindContactTool)->handle(new Request(['name' => 'Northern']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();
    expect($content)->toContain('Northern Supply Co');
    expect($content)->toContain('Northern Lights Cafe');
    expect($content)->toContain('be more specific');
});

it('FindContact: labels an other name so the agent knows it carries no statement', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->otherName()->create([
        'company_id' => $company->id,
        'display_name' => 'Raffle winner',
    ]);

    bindMcpTenant($company);

    $response = (new FindContactTool)->handle(new Request(['name' => 'Raffle']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();
    expect($content)->toContain('Raffle winner (Other name)');
    expect($content)->not->toContain('Receivable statement');
    expect($content)->not->toContain('Payable statement');
});

it('FindContact: returns a friendly message when no contact matches', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Plumbing',
        'is_customer' => true,
    ]);

    bindMcpTenant($company);

    $response = (new FindContactTool)->handle(new Request(['name' => 'Nonexistent Vendor']));

    expect((string) $response->content())->toContain('No customer or vendor matched');
});

it('FindContact: denies a key lacking both sales and purchases scopes', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Acme Plumbing',
        'is_customer' => true,
        'ar_balance_cents' => 125000,
    ]);

    bindMcpTenant($company, ['inventory:read']);

    $response = (new FindContactTool)->handle(new Request(['name' => 'Acme']));

    expect($response->isError())->toBeTrue();
});
