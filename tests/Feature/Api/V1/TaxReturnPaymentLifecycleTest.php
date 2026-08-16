<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Tax\TaxReturnFiler;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();

    // Post a taxable invoice and file a return so payments have a filed target.
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-02-01',
    ]);
    $totals = app(TaxCalculator::class)->line('1', 10000, $this->gst);
    $invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $this->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-'.uniqid(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'status' => TaxReturnStatus::Draft,
    ]);
    $this->return = app(TaxReturnFiler::class)->file($return);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function trpAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function trpPayload(array $overrides = []): array
{
    return array_merge([
        'tax_return_id' => test()->return->id,
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => test()->bank->id,
        'net_amount_cents' => 500,
    ], $overrides);
}

it('lists tax return payments with pagination meta', function () {
    $this->postJson('/api/v1/tax-return-payments', trpPayload(), trpAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/tax-return-payments', trpAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('creates and posts a payment by default', function () {
    $response = $this->postJson('/api/v1/tax-return-payments', trpPayload(), trpAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 500);

    expect($response->json('data.journal_entry_id'))->not->toBeNull();
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('shows a single payment', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader())->json('data.id');

    $this->getJson("/api/v1/tax-return-payments/{$id}", trpAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader())->json('data.id');

    $this->postJson("/api/v1/tax-return-payments/{$id}/post", [], trpAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(TaxReturnPayment::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/tax-return-payments/{$id}", trpPayload([
        'net_amount_cents' => 300,
        'reference' => 'CONF-9',
    ]), trpAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.net_amount_cents', 300)
        ->assertJsonPath('data.reference', 'CONF-9')
        ->assertJsonPath('data.status', 'draft');
});

it('refuses to edit a posted payment (no repost)', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(), trpAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/tax-return-payments/{$id}", trpPayload(['net_amount_cents' => 200]), trpAuthHeader())
        ->assertStatus(409);
});

it('validates that penalty requires an account', function () {
    $this->postJson('/api/v1/tax-return-payments', trpPayload([
        'penalty_cents' => 100,
    ]), trpAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('penalty_account_id');
});

it('voids a posted payment and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(), trpAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/tax-return-payments/{$id}", [], trpAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(TaxReturnPayment::withoutGlobalScopes()->find($id)->status)->toBe(TaxReturnPaymentStatus::Void);
});

it('deletes a draft payment', function () {
    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/tax-return-payments/{$id}", [], trpAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/tax-return-payments/{$id}", trpAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s payment', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/tax-return-payments', trpPayload(['post' => false]), trpAuthHeader())->json('data.id');

    $this->getJson("/api/v1/tax-return-payments/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['tax:read']);

    $this->getJson('/api/v1/tax-return-payments', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/tax-return-payments', trpPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
