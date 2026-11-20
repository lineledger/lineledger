<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\CustomerPaymentsTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\PaymentMethod;
use App\Models\User;
use Laravel\Mcp\Request;
use Tests\Support\Mcp\StatementOfAccountFixture;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function customerPayments(array $args): string
{
    return (string) (new CustomerPaymentsTool)->handle(new Request($args))->content();
}

it('CustomerPayments: lists each receipt, what it paid, and the credit left over', function () {
    $soa = new StatementOfAccountFixture;
    $invoice = $soa->fullServiceInvoice();
    PaymentMethod::create(['name' => 'Insurer Payout']);

    $insurer = overpaymentReceipt($invoice->contact, 600000, $invoice, '2026-10-01', 'Insurer Payout');
    $deposit = overpaymentReceipt($invoice->contact, 25000, null, '2026-10-05', 'Cash');
    bindMcpTenant($soa->company);

    $text = customerPayments(['customer' => '90/002']);

    expect($text)
        ->toContain('Payments and credits for Example, Robin - 90/002')
        ->toContain("{$insurer->receipt_no} (API id {$insurer->id}) · Insurer Payout: CA\$6,000.00")
        ->toContain("applied to INV 90/002 (API id {$invoice->id}): CA\$6,000.00")
        ->toContain("{$deposit->receipt_no} (API id {$deposit->id}) · Cash: CA\$250.00")
        ->toContain('unapplied (held as credit): CA$250.00')
        ->toContain('Unapplied credit the customer holds now: CA$250.00 in unapplied payments');
});

it('CustomerPayments: narrows to a date window but still reports the credit held now', function () {
    $soa = new StatementOfAccountFixture;
    $invoice = $soa->fullServiceInvoice();
    $early = overpaymentReceipt($invoice->contact, 10000, null, '2026-09-10');
    $late = overpaymentReceipt($invoice->contact, 20000, null, '2026-10-10');
    bindMcpTenant($soa->company);

    $text = customerPayments(['customer' => (string) $invoice->contact_id, 'start' => '2026-10-01']);

    expect($text)->toContain($late->receipt_no)
        ->not->toContain($early->receipt_no)
        ->toContain('Unapplied credit the customer holds now: CA$300.00');
});

it('CustomerPayments: asks which customer when a name matches several', function () {
    $soa = new StatementOfAccountFixture;
    $soa->customer('Smith, Ann - 90/201');
    $soa->customer('Smith, Bob - 90/202');
    bindMcpTenant($soa->company);

    expect(customerPayments(['customer' => 'smith']))
        ->toContain('Several customers match "smith"')
        ->toContain('Smith, Ann - 90/201 (API id');
});

it('CustomerPayments: cannot reach another organization\'s customer', function () {
    $other = new StatementOfAccountFixture;
    $foreign = $other->fullServiceInvoice();

    bindMcpTenant(Company::factory()->create());

    expect(customerPayments(['customer' => (string) $foreign->contact_id]))->toContain('No customer matched')
        ->and(customerPayments(['customer' => 'Example, Robin']))->toContain('No customer matched');
});

it('CustomerPayments: needs the sales scope and access to receipts', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['reports:read']);
    expect((new CustomerPaymentsTool)->handle(new Request(['customer' => 'x']))->isError())->toBeTrue();

    $creator = User::factory()->create();
    $company->memberships()->create(['user_id' => $creator->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Banking->value]]);
    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Banking key', $creator->id);
    app()->instance('current_api_key', $key);

    expect((string) (new CustomerPaymentsTool)->handle(new Request(['customer' => 'x']))->content())->toContain('do not have access');
});
