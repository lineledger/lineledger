<?php

use App\Mcp\Tools\ListInvoicesTool;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Laravel\Mcp\Request;

it('ListInvoices: lists invoices most recent first with number customer status and total', function () {
    $company = Company::factory()->create();

    $customer = Contact::factory()->create([
        'company_id' => $company->id,
        'is_customer' => true,
        'display_name' => 'Acme Co',
    ]);

    Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-1001',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => 'posted',
        'total_cents' => 150000,
    ]);

    Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-1002',
        'invoice_date' => '2026-05-15',
        'due_date' => '2026-06-15',
        'status' => 'paid',
        'total_cents' => 250000,
    ]);

    bindMcpTenant($company);

    $tool = new ListInvoicesTool;
    $response = $tool->handle(new Request([]));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('INV-1001');
    expect($content)->toContain('INV-1002');
    expect($content)->toContain('Acme Co');
    expect($content)->toContain('1,500.00');
    expect($content)->toContain('2,500.00');

    // Most recent first: INV-1002 (2026-05-15) appears before INV-1001 (2026-05-01).
    expect(strpos($content, 'INV-1002'))->toBeLessThan(strpos($content, 'INV-1001'));
});

it('ListInvoices: filters invoices by status', function () {
    $company = Company::factory()->create();

    $customer = Contact::factory()->create([
        'company_id' => $company->id,
        'is_customer' => true,
        'display_name' => 'Globex',
    ]);

    Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-2001',
        'invoice_date' => '2026-04-01',
        'due_date' => '2026-04-30',
        'status' => 'posted',
        'total_cents' => 100000,
    ]);

    Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-2002',
        'invoice_date' => '2026-04-10',
        'due_date' => '2026-05-10',
        'status' => 'draft',
        'total_cents' => 200000,
    ]);

    bindMcpTenant($company);

    $tool = new ListInvoicesTool;
    $response = $tool->handle(new Request(['status' => 'draft']));

    expect($response->isError())->toBeFalse();

    $content = (string) $response->content();

    expect($content)->toContain('INV-2002');
    expect($content)->not->toContain('INV-2001');
});

it('ListInvoices: rejects an unknown status value', function () {
    $company = Company::factory()->create();

    bindMcpTenant($company);

    $tool = new ListInvoicesTool;
    $response = $tool->handle(new Request(['status' => 'bogus']));

    $content = (string) $response->content();

    expect($content)->toContain('Unknown invoice status');
});

it('ListInvoices: denies access without the sales read ability', function () {
    $company = Company::factory()->create();

    bindMcpTenant($company, ['reports:read']);

    $tool = new ListInvoicesTool;
    $response = $tool->handle(new Request([]));

    expect($response->isError())->toBeTrue();
});
