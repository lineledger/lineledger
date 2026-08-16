<?php

use App\Enums\AccountSubtype;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->agencyId = $this->gst->agency_id;
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function taxAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function taxReturnPayload(array $overrides = []): array
{
    return array_merge([
        'tax_agency_id' => test()->agencyId,
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ], $overrides);
}

/**
 * Seeds a posted GST invoice so a filed return has lines to snapshot.
 */
function seedTaxableInvoice(int $subtotalCents = 10000): void
{
    app()->instance('current_company', test()->company);

    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-02-01',
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, test()->gst);

    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);
    app()->forgetInstance('current_company');
}

it('lists tax returns with pagination meta', function () {
    $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/tax-returns', taxAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('creates a draft tax return header', function () {
    $response = $this->postJson('/api/v1/tax-returns', taxReturnPayload(['filing_reference' => 'GOV-123']), taxAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.filing_reference', 'GOV-123');

    expect($response->json('data.tax_return_no'))->toStartWith('TR-');
    expect($response->json('data.lines'))->toBe([]);
});

it('shows a single tax return', function () {
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');

    $this->getJson("/api/v1/tax-returns/{$id}", taxAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);
});

it('edits a draft tax return', function () {
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/tax-returns/{$id}", taxReturnPayload(['notes' => 'Updated']), taxAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.notes', 'Updated');
});

it('files a draft and snapshots contributing lines', function () {
    seedTaxableInvoice(10000);

    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');

    $this->postJson("/api/v1/tax-returns/{$id}/file", [], taxAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'filed')
        ->assertJsonPath('data.collected_cents', 500);

    expect(TaxReturn::withoutGlobalScopes()->find($id)->status)->toBe(TaxReturnStatus::Filed);
});

it('refuses to edit a filed tax return', function () {
    seedTaxableInvoice(10000);
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');
    $this->postJson("/api/v1/tax-returns/{$id}/file", [], taxAuthHeader())->assertStatus(200);

    $this->patchJson("/api/v1/tax-returns/{$id}", taxReturnPayload(['notes' => 'nope']), taxAuthHeader())
        ->assertStatus(409);
});

it('voids a filed tax return', function () {
    seedTaxableInvoice(10000);
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');
    $this->postJson("/api/v1/tax-returns/{$id}/file", [], taxAuthHeader())->assertStatus(200);

    $this->postJson("/api/v1/tax-returns/{$id}/void", ['void_reason' => 'Filed in error'], taxAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(TaxReturn::withoutGlobalScopes()->find($id)->void_reason)->toBe('Filed in error');
});

it('deletes a draft tax return', function () {
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/tax-returns/{$id}", [], taxAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/tax-returns/{$id}", taxAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s tax return', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload(), taxAuthHeader())->json('data.id');

    $this->getJson("/api/v1/tax-returns/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['tax:read']);

    $this->getJson('/api/v1/tax-returns', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/tax-returns', taxReturnPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
