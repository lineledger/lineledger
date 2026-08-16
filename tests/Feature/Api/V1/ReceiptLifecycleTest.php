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
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->deposit = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first()
        ?? Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    // A posted invoice the receipt can be applied against.
    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-T1',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => InvoiceStatus::Draft,
    ]);
    $this->invoice->lines()->create([
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'account_id' => $this->income->id,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    $this->invoice->refresh();
    $this->invoice->recalculateTotals();
    app(InvoicePoster::class)->post($this->invoice);

    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function receiptAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function receiptPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => test()->deposit->id,
        'amount_cents' => 10000,
        'applications' => [[
            'invoice_id' => test()->invoice->id,
            'amount_cents' => 10000,
        ]],
    ], $overrides);
}

it('lists receipts with pagination meta', function () {
    $this->postJson('/api/v1/receipts', receiptPayload(), receiptAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/receipts', receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single receipt', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(), receiptAuthHeader())->json('data.id');

    $this->getJson("/api/v1/receipts/{$id}", receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted');
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/receipts', receiptPayload(['post' => false]), receiptAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(['post' => false]), receiptAuthHeader())->json('data.id');

    $this->postJson("/api/v1/receipts/{$id}/post", [], receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(CustomerReceipt::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(['post' => false]), receiptAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/receipts/{$id}", receiptPayload([
        'memo' => 'Updated memo',
        'amount_cents' => 5000,
        'applications' => [[
            'invoice_id' => $this->invoice->id,
            'amount_cents' => 5000,
        ]],
    ]), receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.amount_cents', 5000)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted receipt in place via update', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(), receiptAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/receipts/{$id}", receiptPayload([
        'amount_cents' => 7500,
        'applications' => [[
            'invoice_id' => $this->invoice->id,
            'amount_cents' => 7500,
        ]],
    ]), receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.amount_cents', 7500)
        ->assertJsonPath('data.status', 'posted');
});

it('rejects applications exceeding the receipt amount', function () {
    $this->postJson('/api/v1/receipts', receiptPayload([
        'amount_cents' => 5000,
        'applications' => [[
            'invoice_id' => $this->invoice->id,
            'amount_cents' => 10000,
        ]],
    ]), receiptAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('applications');
});

it('rejects an application for another customer invoice', function () {
    app()->instance('current_company', $this->company);
    $other = Contact::create(['display_name' => 'Other Co', 'is_customer' => true]);
    $otherInvoice = Invoice::create([
        'contact_id' => $other->id,
        'invoice_no' => 'INV-T2',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => InvoiceStatus::Draft,
    ]);
    $otherInvoice->lines()->create([
        'description' => 'X', 'quantity' => '1', 'unit_price_cents' => 5000,
        'account_id' => $this->income->id, 'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0, 'line_total_cents' => 5000, 'line_order' => 0,
    ]);
    $otherInvoice->refresh();
    $otherInvoice->recalculateTotals();
    app(InvoicePoster::class)->post($otherInvoice);
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/receipts', receiptPayload([
        'applications' => [[
            'invoice_id' => $otherInvoice->id,
            'amount_cents' => 5000,
        ]],
    ]), receiptAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('applications.0.invoice_id');
});

it('voids a posted receipt and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(), receiptAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/receipts/{$id}", [], receiptAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(CustomerReceipt::withoutGlobalScopes()->find($id)->status)->toBe(ReceiptStatus::Void);
});

it('deletes a draft receipt', function () {
    $id = $this->postJson('/api/v1/receipts', receiptPayload(['post' => false]), receiptAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/receipts/{$id}", [], receiptAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/receipts/{$id}", receiptAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s receipt', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/receipts', receiptPayload(), receiptAuthHeader())->json('data.id');

    $this->getJson("/api/v1/receipts/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    $this->getJson('/api/v1/receipts', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/receipts', receiptPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

it('allows writes with a sales:write key', function () {
    ['plaintext' => $writePlain] = CompanyApiKey::mint($this->company, 'Write', null, ['sales:write']);

    $this->postJson('/api/v1/receipts', receiptPayload(), ['Authorization' => "Bearer {$writePlain}"])
        ->assertStatus(201);
});
