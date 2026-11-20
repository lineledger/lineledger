<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\GetInvoiceTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Laravel\Mcp\Request;
use Tests\Support\Mcp\StatementOfAccountFixture;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function getInvoice(array $args): string
{
    return (string) (new GetInvoiceTool)->handle(new Request($args))->content();
}

it('GetInvoice: returns INV 90/001 with every line, tax flags, tax by code and totals', function () {
    $soa = new StatementOfAccountFixture;
    $invoice = $soa->repatriationInvoice();
    bindMcpTenant($soa->company);

    $text = getInvoice(['invoice' => 'INV 90/001']);

    expect($text)
        ->toContain("Invoice INV 90/001 (API id {$invoice->id}) — Posted")
        ->toContain('100 Sample Road, Kelowna, BC  V1Y 0A1')
        ->toContain('family@example.com')
        ->toContain('Airfare - Family Travel — 1 × CA$3,000.00 = CA$3,000.00 | no tax (E)')
        ->toContain('Death Certificate — 2 × CA$27.00 = CA$54.00 | EX (E)')
        ->toContain('Casket: Metal Transfer Casket — 1 × CA$1,695.00 = CA$1,695.00 | GST, PST-BC (GP) | item: Metal Transfer Casket [CSK-MT]')
        ->toContain('GST 5%: on CA$5,070.00 → CA$253.50')
        ->toContain('PST-BC 7%: on CA$1,695.00 → CA$118.65')
        ->toContain('Subtotal: CA$9,467.00')
        ->toContain('Total: CA$9,839.15')
        ->toContain('Payments applied: none')
        ->toContain('Balance due: CA$9,839.15');

    // Thirteen numbered lines, in invoice order.
    expect(preg_match_all('/^\d+\. /m', $text))->toBe(13)
        ->and(strpos($text, '1. Professional & Staff Services'))->toBeLessThan(strpos($text, '13. Airfare - Family Travel'));
});

it('GetInvoice: returns INV 90/002 as JSON with typed descriptions, sections and the tax summary', function () {
    $soa = new StatementOfAccountFixture;
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    $json = json_decode(getInvoice(['invoice' => 'inv 90/002', 'format' => 'json']), true, flags: JSON_THROW_ON_ERROR);

    expect($json['lines'])->toHaveCount(11)
        ->and($json['lines'][8]['description'])->toBe('Cemetery Opening and Closing')
        ->and($json['lines'][8]['section'])->toBe('disbursement')
        ->and($json['lines'][8]['tax_flags'])->toBe('G')
        ->and($json['lines'][9]['tax_flags'])->toBe('E')
        ->and($json['lines'][5]['tax_flags'])->toBe('GP')
        ->and($json['lines'][0]['section'])->toBe('services')
        ->and($json['lines'][0]['amount'])->toBe('3495.00');

    $tax = collect($json['tax'])->keyBy('code');
    expect($tax['GST']['base'])->toBe('10100.00')
        ->and($tax['GST']['amount'])->toBe('505.00')
        ->and($tax['PST-BC']['base'])->toBe('1900.00')
        ->and($tax['PST-BC']['amount'])->toBe('133.00')
        ->and($json['totals']['subtotal'])->toBe('10175.00')
        ->and($json['totals']['total'])->toBe('10813.00')
        ->and($json['totals']['balance_due'])->toBe('10813.00')
        ->and($json['customer']['name'])->toBe('Example, Robin - 90/002');
});

it('GetInvoice: lists the payments applied and the balance left', function () {
    $soa = new StatementOfAccountFixture;
    $invoice = $soa->fullServiceInvoice();
    $receipt = overpaymentReceipt($invoice->contact, 500000, null, '2026-10-15', 'Cash');
    $receipt->applications()->delete();
    // Apply part of the payment through the real posting path.
    $partial = overpaymentReceipt($invoice->contact, 400000, $invoice, '2026-10-16', 'Cash');
    bindMcpTenant($soa->company);

    $text = getInvoice(['invoice' => (string) $invoice->id]);

    expect($text)
        ->toContain("{$partial->receipt_no} (API id {$partial->id}) · Cash: CA\$4,000.00")
        ->toContain('Balance due: CA$6,813.00')
        ->not->toContain($receipt->receipt_no);
});

it('GetInvoice: offers close matches when the number is not exact', function () {
    $soa = new StatementOfAccountFixture;
    $soa->fullServiceInvoice();
    bindMcpTenant($soa->company);

    expect(getInvoice(['invoice' => '90/002']))
        ->toContain('No invoice is numbered exactly "90/002"')
        ->toContain('INV 90/002');
});

it('GetInvoice: cannot see another organization\'s invoice by number or id', function () {
    $other = new StatementOfAccountFixture;
    $foreign = $other->fullServiceInvoice();

    $mine = Company::factory()->create();
    bindMcpTenant($mine);

    expect(getInvoice(['invoice' => 'INV 90/002']))->toContain('No invoice is numbered "INV 90/002"')
        ->and(getInvoice(['invoice' => (string) $foreign->id]))->toContain('No invoice is numbered');
});

it('GetInvoice: prints a foreign-currency invoice in its own currency with the home total', function () {
    $company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $company);
    $customer = Contact::factory()->create(['company_id' => $company->id, 'is_customer' => true, 'display_name' => 'US Client']);

    $invoice = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'INV-USD-1', 'invoice_date' => '2026-05-01', 'due_date' => '2026-05-31',
        'status' => 'posted', 'currency_code' => 'USD', 'fx_rate' => '1.350000', 'subtotal_cents' => 10000, 'total_cents' => 10000,
        'home_total_cents' => 13500,
    ]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
    $invoice->lines()->create(['account_id' => $income->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000, 'line_total_cents' => 10000, 'line_order' => 0]);
    bindMcpTenant($company);

    expect(getInvoice(['invoice' => 'INV-USD-1']))
        ->toContain('Total: $100.00')
        ->toContain('Currency USD at 1.35;')
        ->toContain('CA$135.00');
});

it('GetInvoice: denies a key without the sales read scope', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['reports:read']);

    expect((new GetInvoiceTool)->handle(new Request(['invoice' => 'X']))->isError())->toBeTrue();
});

it('GetInvoice: denies a key whose creator cannot view invoices', function () {
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    $company->memberships()->create(['user_id' => $creator->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Banking->value]]);
    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Banking key', $creator->id);
    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);

    $response = (new GetInvoiceTool)->handle(new Request(['invoice' => 'X']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('do not have access');
});

it('GetInvoice: reads a "GST (5%)" line posted to GST payable as tax, not a disbursement', function () {
    $soa = new StatementOfAccountFixture;
    $gstPayable = Account::query()->where('subtype', AccountSubtype::TaxPayable->value)->orderBy('code')->firstOrFail();
    $customer = $soa->customer('Imported, Sam - 90/084');
    // An imported invoice: no tax codes; the tax is a line of its own.
    $soa->post($customer, 'INV 90/084', '2026-09-02', [
        ['Transfer Services', 1, 19500, []],
        ['Provincial Death Certificate', 1, 5400, [], $soa->disbursements],
        ['GST (5%)', 1, 975, [], $gstPayable],
    ]);
    bindMcpTenant($soa->company);

    $text = getInvoice(['invoice' => 'INV 90/084']);
    $json = json_decode(getInvoice(['invoice' => 'INV 90/084', 'format' => 'json']), true, flags: JSON_THROW_ON_ERROR);

    expect($text)
        ->toContain('GST (5%) — 1 × CA$9.75 = CA$9.75 | no tax (E)')
        ->toContain('Tax charged as separate lines')
        ->toContain('- GST (5%): CA$9.75')
        ->toContain('per-line tax flags (G/P) cannot be derived')
        ->and(array_column($json['lines'], 'section'))->toBe(['services', 'disbursement', 'tax'])
        ->and($json['tax_lines'][0]['amount'])->toBe('9.75');
});
