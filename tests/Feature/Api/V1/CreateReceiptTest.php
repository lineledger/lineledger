<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-9000',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-06-01',
    ]);

    $this->invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($this->invoice->fresh('lines'));
    $this->invoice->refresh();

    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('creates an applied receipt and marks the invoice paid', function () {
    $response = $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 20000,
        'applications' => [
            ['invoice_id' => $this->invoice->id, 'amount_cents' => 20000],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 20000)
        ->assertJsonPath('data.unapplied_cents', 0)
        ->assertJsonPath('data.applications.0.amount_cents', 20000);

    $receipt = CustomerReceipt::query()->withoutGlobalScopes()->firstOrFail();
    expect($receipt->status)->toBe(ReceiptStatus::Posted);
    expect($receipt->receipt_no)->toStartWith('REC-');

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(InvoiceStatus::Paid);
    expect($this->invoice->amount_paid_cents)->toBe(20000);
});

it('creates an unapplied receipt when applications are omitted', function () {
    $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 15000,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.unapplied_cents', 15000)
        ->assertJsonPath('data.applications', []);

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(InvoiceStatus::Posted);
    expect($this->invoice->amount_paid_cents)->toBe(0);
});

it('reports the invoice balance and the receipt\'s unapplied remainder as the receipt is applied', function () {
    $headers = ['Authorization' => "Bearer {$this->plain}"];

    $this->getJson("/api/v1/invoices/{$this->invoice->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.balance_cents', 20000);

    $receiptId = $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 15000,
        'applications' => [
            ['invoice_id' => $this->invoice->id, 'amount_cents' => 5000],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.unapplied_cents', 10000)
        ->json('data.id');

    $this->getJson("/api/v1/invoices/{$this->invoice->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'partial')
        ->assertJsonPath('data.amount_paid_cents', 5000)
        ->assertJsonPath('data.balance_cents', 15000);

    // Re-saving the receipt with a larger application — the full header plus
    // the complete applications list — moves credit onto the invoice.
    $this->patchJson("/api/v1/receipts/{$receiptId}", [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 15000,
        'applications' => [
            ['invoice_id' => $this->invoice->id, 'amount_cents' => 15000],
        ],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.unapplied_cents', 0);

    $this->getJson("/api/v1/invoices/{$this->invoice->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.amount_paid_cents', 15000)
        ->assertJsonPath('data.balance_cents', 5000);
});

it('rejects applications summing above the receipt amount', function () {
    $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
        'applications' => [
            ['invoice_id' => $this->invoice->id, 'amount_cents' => 10000],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['applications']);
});

it('rejects applications to invoices belonging to a different contact', function () {
    app()->instance('current_company', $this->company);
    $other = Contact::create(['display_name' => 'Other', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/receipts', [
        'contact_id' => $other->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
        'applications' => [
            ['invoice_id' => $this->invoice->id, 'amount_cents' => 5000],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['applications.0.invoice_id']);
});
