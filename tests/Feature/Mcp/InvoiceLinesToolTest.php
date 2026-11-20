<?php

use App\Actions\Sales\SaveInvoice;
use App\Mcp\Tools\InvoiceLinesTool;
use App\Models\Company;
use App\Models\Invoice;
use Laravel\Mcp\Request;
use Tests\Support\Mcp\StatementOfAccountFixture;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function invoiceLines(array $args): string
{
    return (string) (new InvoiceLinesTool)->handle(new Request($args))->content();
}

it('InvoiceLines: finds lines by item across invoices with counts and totals', function () {
    $soa = new StatementOfAccountFixture;
    $soa->repatriationInvoice();
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    $text = invoiceLines(['item' => 'csk-mt']);

    expect($text)
        ->toContain('CAD: 1 line on 1 invoice, quantity 1, amount CA$1,695.00 (before tax)')
        ->toContain('INV 90/001')
        ->toContain('Casket: Metal Transfer Casket | 1 × CA$1,695.00 = CA$1,695.00 | GST, PST-BC (GP) | item: Metal Transfer Casket')
        ->not->toContain('INV 90/002');
});

it('InvoiceLines: searches the typed description and narrows by customer and dates', function () {
    $soa = new StatementOfAccountFixture;
    $soa->repatriationInvoice();
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    $both = invoiceLines(['description' => 'death certificate']);
    expect($both)->toContain('CAD: 2 lines on 2 invoices, quantity 3, amount CA$81.00')
        // Newest invoice first.
        ->and(strpos($both, 'INV 90/001'))->toBeLessThan(strpos($both, 'INV 90/002'));

    expect(invoiceLines(['description' => 'death certificate', 'customer' => 'robin']))
        ->toContain('CAD: 1 line on 1 invoice')
        ->not->toContain('INV 90/001');

    expect(invoiceLines(['description' => 'death certificate', 'start' => '2026-10-01', 'end' => '2026-10-31']))
        ->toContain('INV 90/001')
        ->not->toContain('INV 90/002');
});

it('InvoiceLines: filters by account code', function () {
    $soa = new StatementOfAccountFixture;
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    expect(invoiceLines(['account' => 'ZZ-2400']))
        ->toContain('CAD: 3 lines on 1 invoice, quantity 3, amount CA$2,775.00');
});

it('InvoiceLines: leaves drafts out unless asked, and matches wildcards literally', function () {
    $soa = new StatementOfAccountFixture;
    $posted = $soa->fullServiceInvoice();
    $draft = app(SaveInvoice::class)->handle([
        'contact_id' => $posted->contact_id,
        'invoice_no' => 'INV DRAFT',
        'invoice_date' => '2026-11-01',
        'lines' => [['description' => '100% cotton shroud', 'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $soa->income->id]],
    ]);
    bindMcpTenant($soa->company);

    expect(invoiceLines(['description' => 'shroud']))->toContain('No invoice lines matched')
        ->and(invoiceLines(['description' => 'shroud', 'status' => 'draft']))->toContain($draft->invoice_no)
        // "%" is literal: only the line that really contains "0% c" matches.
        ->and(invoiceLines(['description' => '0% c', 'status' => 'all']))->toContain('INV DRAFT')
        ->and(invoiceLines(['description' => '_', 'status' => 'all']))->toContain('No invoice lines matched');
});

it('InvoiceLines: says when the list is cut short', function () {
    $soa = new StatementOfAccountFixture;
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    expect(invoiceLines(['limit' => 3]))->toContain('Showing the newest 3 of 11 lines');
});

it('InvoiceLines: never returns another organization\'s lines', function () {
    $other = new StatementOfAccountFixture;
    $other->fullServiceInvoice();

    $mine = Company::factory()->create();
    bindMcpTenant($mine);

    expect(invoiceLines(['description' => 'death']))->toContain('No invoice lines matched')
        ->and(Invoice::query()->count())->toBe(0);
});

it('InvoiceLines: rejects an unknown status and a key without the sales scope', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);
    expect(invoiceLines(['status' => 'bogus']))->toContain('Unknown invoice status');

    bindMcpTenant($company, ['reports:read']);
    expect((new InvoiceLinesTool)->handle(new Request([]))->isError())->toBeTrue();
});
