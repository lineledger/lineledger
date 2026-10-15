<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Services\EditLocks\EditLockManager;

/*
 * POST /api/v1/invoices/{invoice}/refund — refund a customer's overpayment
 * (a payment's unapplied credit) by card, with no credit memo.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->headers = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Patel Family', 'is_customer' => true]);
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $this->invoice = overpaymentInvoice($this->customer, 20000);
    $this->card = overpaymentReceipt($this->customer, 20000, $this->invoice);
    $this->cheque = overpaymentReceipt($this->customer, 20000);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/** @return array<string, mixed> */
function invoiceRefundBody(array $overrides = []): array
{
    return [
        'refunded_receipt_id' => test()->cheque->id,
        'refund_date' => now()->toDateString(),
        'amount_cents' => 20000,
        'deposit_to_account_id' => test()->undeposited->id,
        ...$overrides,
    ];
}

it('refunds the overpayment as a posted negative receipt linked to the payment and the invoice', function () {
    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(['reference' => 'TERM-7']), $this->headers)
        ->assertStatus(201)
        ->assertJsonPath('data.amount_cents', -20000)
        ->assertJsonPath('data.refunded_receipt_id', $this->cheque->id)
        ->assertJsonPath('data.from_invoice_id', $this->invoice->id)
        ->assertJsonPath('data.credit_memo_id', null)
        ->assertJsonPath('data.unapplied_cents', 0)
        ->assertJsonPath('data.reference', 'TERM-7')
        ->assertJsonPath('data.status', 'posted');

    // The payment's credit is spent, and the API says so.
    $this->getJson("/api/v1/receipts/{$this->cheque->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.unapplied_cents', 0)
        ->assertJsonPath('data.refunded_cents', 20000);

    $this->getJson("/api/v1/invoices/{$this->invoice->id}", $this->headers)
        ->assertJsonPath('data.status', InvoiceStatus::Paid->value)
        ->assertJsonPath('data.balance_cents', 0);
});

it('rejects an amount beyond the payment\'s credit', function () {
    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(['amount_cents' => 20001]), $this->headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount_cents']);
});

it('rejects a payment of another customer, another company, or one that is not posted', function () {
    app()->instance('current_company', $this->company);
    $stranger = Contact::create(['display_name' => 'Stranger', 'is_customer' => true]);
    $strangersPayment = overpaymentReceipt($stranger, 5000);
    $draft = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-DRAFT',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
    ]);

    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $outsider = Contact::create(['display_name' => 'Outsider', 'is_customer' => true]);
    $outsidersPayment = overpaymentReceipt($outsider, 5000);
    app()->forgetInstance('current_company');

    foreach ([$strangersPayment->id, $draft->id, $outsidersPayment->id] as $receiptId) {
        $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(['refunded_receipt_id' => $receiptId, 'amount_cents' => 1000]), $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refunded_receipt_id']);
    }

    expect(CustomerReceipt::query()->withoutGlobalScopes()->whereNotNull('refunded_receipt_id')->exists())->toBeFalse();
});

it('answers 409 for a draft or void invoice', function () {
    app()->instance('current_company', $this->company);
    $this->invoice->forceFill(['status' => InvoiceStatus::Void])->save();
    app()->forgetInstance('current_company');

    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(), $this->headers)
        ->assertStatus(409);
});

it('answers 423 while the payment or the invoice is being edited in the web app', function () {
    $jane = editLockMember($this->company, CompanyRole::Accountant);
    $locks = app(EditLockManager::class);

    app()->instance('current_company', $this->company);
    $lease = $locks->acquire($this->cheque, $jane);
    app()->forgetInstance('current_company');

    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(), $this->headers)
        ->assertStatus(423)
        ->assertHeader('Retry-After');

    app()->instance('current_company', $this->company);
    $locks->release($lease->token, $jane);
    $locks->acquire($this->invoice, $jane);
    app()->forgetInstance('current_company');

    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(), $this->headers)
        ->assertStatus(423);

    expect(CustomerReceipt::query()->withoutGlobalScopes()->whereNotNull('refunded_receipt_id')->exists())->toBeFalse();
});

it('needs a key that can write invoices', function () {
    ['plaintext' => $readOnly] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    $this->postJson("/api/v1/invoices/{$this->invoice->id}/refund", invoiceRefundBody(), ['Authorization' => "Bearer {$readOnly}"])
        ->assertStatus(403);
});
