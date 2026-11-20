<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\FindContactTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\User;
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

it('FindContact: shows the contact card — addresses, email, phone, API id — but never the tax number or notes', function (): void {
    $company = Company::factory()->create();

    $contact = Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Example, Robin - 90/002',
        'is_customer' => true,
        'email' => 'maria@example.com',
        'phone' => '604-555-0100',
        'billing_line1' => '100 Sample Road',
        'billing_city' => 'Kelowna',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V1Y 0A1',
        'shipping_line1' => '1 Chapel Road',
        'shipping_city' => 'Aldergrove',
        'tax_number' => '123-45-6789',
        'notes' => 'Internal: difficult file',
    ]);

    bindMcpTenant($company);

    $content = (string) (new FindContactTool)->handle(new Request(['name' => '90/002']))->content();

    expect($content)
        ->toContain("Contact details: Example, Robin - 90/002 (API id {$contact->id})")
        ->toContain('Billing address: 100 Sample Road, Kelowna, BC  V1Y 0A1')
        ->toContain('Shipping address: 1 Chapel Road, Aldergrove')
        ->toContain('Email: maria@example.com')
        ->toContain('Phone: 604-555-0100')
        ->not->toContain('123-45-6789')
        ->not->toContain('difficult file');
});

it('FindContact: does not match an employee-only contact', function (): void {
    $company = Company::factory()->create();

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Jane Employee',
        'is_customer' => false,
        'is_vendor' => false,
        'is_employee' => true,
        'billing_line1' => '12 Private Lane',
    ]);

    bindMcpTenant($company);

    expect((string) (new FindContactTool)->handle(new Request(['name' => 'Jane']))->content())
        ->toContain('No customer or vendor matched')
        ->not->toContain('Private Lane');
});

it('FindContact: hides a customer\'s card from a key whose creator only sees vendors', function (): void {
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $creator->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Vendors->value],
    ]);
    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Vendors key', $creator->id);
    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);

    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Customer Only',
        'is_customer' => true,
        'is_vendor' => false,
        'billing_line1' => '5 Hidden Way',
    ]);
    Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Vendor Only',
        'is_customer' => false,
        'is_vendor' => true,
        'billing_line1' => '7 Supplier Street',
    ]);

    $find = fn (string $name): string => (string) (new FindContactTool)->handle(new Request(['name' => $name]))->content();

    expect($find('Customer Only'))->not->toContain('5 Hidden Way')
        ->and($find('Vendor Only'))->toContain('7 Supplier Street');

    app()->forgetInstance('current_api_key');
});

it('FindContact: matches "%" and "_" literally', function (): void {
    $company = Company::factory()->create();
    Contact::factory()->create(['company_id' => $company->id, 'display_name' => 'Acme 50% Off', 'is_customer' => true]);
    Contact::factory()->create(['company_id' => $company->id, 'display_name' => 'Acme 500 Club', 'is_customer' => true]);
    bindMcpTenant($company);

    expect((string) (new FindContactTool)->handle(new Request(['name' => '50%']))->content())
        ->toContain('Acme 50% Off')
        ->not->toContain('Acme 500 Club');
});
