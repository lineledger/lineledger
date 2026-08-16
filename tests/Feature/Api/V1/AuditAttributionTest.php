<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain, 'key' => $key] = CompanyApiKey::mint($this->company, 'Storefront sync');
    $this->plain = $plain;
    $this->apiKey = $key;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('attributes an API-created invoice posting to the calling api key', function () {
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"])->assertStatus(201);

    $row = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AuditAction::InvoicePosted->value)
        ->latest('id')
        ->firstOrFail();

    expect($row->api_key_id)->toBe($this->apiKey->id)
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->journal_entry_id)->not->toBeNull();
});

it('attributes an API-created receipt posting to the calling api key', function () {
    app()->instance('current_company', $this->company);
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-9001',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-06-01',
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice->fresh('lines'));
    $invoice->refresh();
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 20000,
        'applications' => [
            ['invoice_id' => $invoice->id, 'amount_cents' => 20000],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])->assertStatus(201);

    $row = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AuditAction::CustomerReceiptPosted->value)
        ->latest('id')
        ->firstOrFail();

    expect($row->api_key_id)->toBe($this->apiKey->id);
});

it('attributes an API-created credit memo posting to the calling api key', function () {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 3000, 'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"])->assertStatus(201);

    $row = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AuditAction::CreditMemoPosted->value)
        ->latest('id')
        ->firstOrFail();

    expect($row->api_key_id)->toBe($this->apiKey->id);
});

it('leaves api_key_id null for postings done from the web UI', function () {
    app()->instance('current_company', $this->company);
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-7777',
        'invoice_date' => '2026-05-20',
        'due_date' => '2026-06-19',
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 1000,
        'line_subtotal_cents' => 1000,
        'line_tax_cents' => 0,
        'line_total_cents' => 1000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice->fresh('lines'));
    app()->forgetInstance('current_company');

    $row = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AuditAction::InvoicePosted->value)
        ->latest('id')
        ->firstOrFail();

    expect($row->api_key_id)->toBeNull();
});

it('keeps the audit hash chain intact across API-driven postings', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/invoices', [
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-05-20',
            'lines' => [[
                'quantity' => '1', 'unit_price_cents' => 1000 * $i, 'account_id' => $this->income->id,
            ]],
        ], ['Authorization' => "Bearer {$this->plain}"])->assertStatus(201);
    }

    $exit = Artisan::call('audit:verify', ['company' => $this->company->id]);

    expect($exit)->toBe(0);
});
