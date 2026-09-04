<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\TaxCode;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('creates and posts an invoice in a single call', function () {
    $response = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'due_date' => '2026-06-19',
        'lines' => [[
            'description' => 'Consulting',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 10000)
        ->assertJsonPath('data.lines.0.line_total_cents', 10000);

    $invoice = Invoice::query()->withoutGlobalScopes()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Posted);
    expect($invoice->journal_entry_id)->not->toBeNull();
    expect($invoice->invoice_no)->toStartWith('INV-');
});

it('computes per-line tax via TaxCalculator', function () {
    $gst = TaxCode::query()->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('code', 'GST')
        ->firstOrFail();

    $response = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'due_date' => '2026-06-19',
        'lines' => [[
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'account_id' => $this->income->id,
            'tax_code_id' => $gst->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.subtotal_cents', 10000)
        ->assertJsonPath('data.tax_cents', 500)
        ->assertJsonPath('data.total_cents', 10500);
});

it('rejects invoices for contacts from another company', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = Contact::create(['display_name' => 'X', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/invoices', [
        'contact_id' => $foreign->id,
        'invoice_date' => '2026-05-20',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 1000, 'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contact_id']);
});

it('requires at least one line', function () {
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});

it('returns 422 with a clear message when the period is locked', function () {
    $this->company->update(['lock_date' => '2026-12-31']);

    $response = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 1000, 'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('locked')
        ->and(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepts a custom invoice_no (case-file number) and continues it for the next native invoice', function () {
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'invoice_no' => 'INV 26/123',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.invoice_no', 'INV 26/123');

    // A subsequent native invoice (no invoice_no) continues the custom format.
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-21',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 100, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.invoice_no', 'INV 26/124');
});

it('rejects a duplicate invoice_no', function () {
    $payload = [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'invoice_no' => 'INV 26/500',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id]],
    ];
    $this->postJson('/api/v1/invoices', $payload, ['Authorization' => "Bearer {$this->plain}"])->assertStatus(201);
    $this->postJson('/api/v1/invoices', $payload, ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['invoice_no']);
});

it('accepts a negative line and posts it as a debit to its account', function () {
    // The seeded chart has two income accounts (Sales, Services); use the
    // second as the contra-revenue "discount" account for this invoice.
    app()->instance('current_company', $this->company);
    $discounts = Account::query()
        ->where('subtype', AccountSubtype::Income->value)
        ->where('id', '!=', $this->income->id)
        ->firstOrFail();
    app()->forgetInstance('current_company');

    $response = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-09-04',
        'lines' => [
            ['description' => 'Simple Cremation', 'quantity' => '1', 'unit_price_cents' => 101151, 'account_id' => $this->income->id],
            ['description' => 'Less Discount for Members of Memorial Society of BC (10%)', 'quantity' => '1', 'unit_price_cents' => -10115, 'account_id' => $discounts->id],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 91036)
        ->assertJsonPath('data.lines.1.line_total_cents', -10115);

    $invoice = Invoice::query()->withoutGlobalScopes()->firstOrFail();
    $lines = JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $invoice->journal_entry_id)->get();

    $discount = $lines->firstWhere('account_id', $discounts->id);
    expect((int) $discount->debit_cents)->toBe(10115);
    expect((int) $discount->credit_cents)->toBe(0);
    expect((int) $lines->firstWhere('account_id', $this->income->id)->credit_cents)->toBe(101151);
    expect((int) $lines->sum('debit_cents'))->toBe((int) $lines->sum('credit_cents'));
});

it('rejects an invoice whose lines net to a credit', function () {
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-09-04',
        'lines' => [
            ['quantity' => '1', 'unit_price_cents' => 1000, 'account_id' => $this->income->id],
            ['quantity' => '1', 'unit_price_cents' => -1500, 'account_id' => $this->income->id],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines' => 'The invoice total must be greater than zero. Use a credit memo for a net credit.']);

    expect(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('rejects an invoice whose lines net to exactly zero', function () {
    $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-09-04',
        'lines' => [
            ['quantity' => '2', 'unit_price_cents' => 500, 'account_id' => $this->income->id],
            ['quantity' => '1', 'unit_price_cents' => -1000, 'account_id' => $this->income->id],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});
