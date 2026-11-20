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

it('ListInvoices: shows the sales rep and can filter by it', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $customer = Contact::factory()->create(['company_id' => $company->id, 'is_customer' => true, 'display_name' => 'Acme Co']);
    $rep = Contact::create(['company_id' => $company->id, 'display_name' => 'Jane Rep', 'is_employee' => true]);

    Invoice::create([
        'company_id' => $company->id, 'contact_id' => $customer->id, 'sales_rep_id' => $rep->id,
        'invoice_no' => 'INV-WITH-REP', 'invoice_date' => '2026-05-01', 'due_date' => '2026-05-31',
        'status' => 'posted', 'total_cents' => 150000,
    ]);

    Invoice::create([
        'company_id' => $company->id, 'contact_id' => $customer->id,
        'invoice_no' => 'INV-NO-REP', 'invoice_date' => '2026-05-02', 'due_date' => '2026-06-01',
        'status' => 'posted', 'total_cents' => 100000,
    ]);

    bindMcpTenant($company);

    $all = (string) (new ListInvoicesTool)->handle(new Request([]))->content();

    expect($all)->toContain('rep: Jane Rep')
        ->and($all)->toContain('rep: no rep');

    $filtered = (string) (new ListInvoicesTool)->handle(new Request(['rep' => 'jane']))->content();

    expect($filtered)->toContain('INV-WITH-REP')
        ->and($filtered)->not->toContain('INV-NO-REP');
});

it('ListInvoices: shows the balance due, filters by number, and prints each invoice in its own currency', function () {
    $company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $company);
    $customer = Contact::factory()->create(['company_id' => $company->id, 'is_customer' => true, 'display_name' => 'Acme Co']);

    $cad = Invoice::create([
        'company_id' => $company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV 90/002',
        'invoice_date' => '2026-05-01', 'due_date' => '2026-05-31', 'status' => 'partial',
        'total_cents' => 100000, 'amount_paid_cents' => 40000,
    ]);
    Invoice::create([
        'company_id' => $company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-USD-7',
        'invoice_date' => '2026-05-02', 'due_date' => '2026-06-01', 'status' => 'posted',
        'currency_code' => 'USD', 'fx_rate' => '1.35', 'total_cents' => 5000, 'home_total_cents' => 6750,
    ]);
    bindMcpTenant($company);

    $all = (string) (new ListInvoicesTool)->handle(new Request([]))->content();
    expect($all)
        ->toContain("INV 90/002 (API id {$cad->id})")
        ->toContain('total CA$1,000.00 | balance due CA$600.00')
        ->toContain('total $50.00 | balance due $50.00')
        ->toContain('each invoice\'s own currency');

    $byNumber = (string) (new ListInvoicesTool)->handle(new Request(['number' => '90/002']))->content();
    expect($byNumber)->toContain('INV 90/002')->not->toContain('INV-USD-7');
});
