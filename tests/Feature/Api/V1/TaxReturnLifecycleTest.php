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
use App\Models\TaxReturnAdjustment;
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

it('creates a draft with adjustments and reports its provisional figures', function () {
    seedTaxableInvoice(10000);
    $payableId = $this->gst->agency->payable_account_id;

    $response = $this->postJson('/api/v1/tax-returns', taxReturnPayload([
        'adjustments' => [
            ['kind' => 'collected', 'account_id' => $payableId, 'amount_cents' => 200, 'memo' => 'Cash sale'],
        ],
    ]), taxAuthHeader())->assertStatus(201);

    $response->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.collected_cents', 700)
        ->assertJsonPath('data.net_cents', 700)
        ->assertJsonPath('data.adjustments.0.kind', 'collected')
        ->assertJsonPath('data.adjustments.0.amount_cents', 200)
        ->assertJsonPath('data.adjustments.0.memo', 'Cash sale');
});

it('keeps adjustments and excluded lines when an update omits them', function () {
    seedTaxableInvoice(10000);
    $payableId = $this->gst->agency->payable_account_id;

    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload([
        'excluded_journal_line_ids' => [987654],
        'adjustments' => [['kind' => 'other', 'account_id' => $payableId, 'amount_cents' => 150]],
    ]), taxAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/tax-returns/{$id}", taxReturnPayload(['notes' => 'Edited']), taxAuthHeader())
        ->assertOk()
        ->assertJsonPath('data.notes', 'Edited')
        ->assertJsonCount(1, 'data.adjustments');

    expect(TaxReturn::withoutGlobalScopes()->findOrFail($id)->excluded_journal_line_ids)->toBe([987654]);

    $this->patchJson("/api/v1/tax-returns/{$id}", taxReturnPayload(['adjustments' => []]), taxAuthHeader())
        ->assertOk()
        ->assertJsonCount(0, 'data.adjustments');
});

it('refuses to file a return that differs from the ledger until the difference is accepted', function () {
    seedTaxableInvoice(10000);
    $payableId = $this->gst->agency->payable_account_id;

    // 10.00 on the return that the ledger doesn't hold: the return is 10.00 over.
    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload([
        'adjustments' => [['kind' => 'other', 'account_id' => $payableId, 'amount_cents' => 1000]],
    ]), taxAuthHeader())->json('data.id');

    $this->postJson("/api/v1/tax-returns/{$id}/file", [], taxAuthHeader())
        ->assertStatus(422)
        ->assertJsonPath('message', 'The return does not agree with the tax payable account; adjust it or accept the difference to file anyway.');

    $this->postJson("/api/v1/tax-returns/{$id}/file", ['accepted_difference_cents' => -500], taxAuthHeader())
        ->assertStatus(422);

    $this->postJson("/api/v1/tax-returns/{$id}/file", ['accepted_difference_cents' => -1000], taxAuthHeader())
        ->assertOk()
        ->assertJsonPath('data.status', 'filed')
        ->assertJsonPath('data.net_cents', 1500)
        ->assertJsonPath('data.reconciliation.difference_cents', -1000)
        ->assertJsonPath('data.reconciliation.accepted_difference_cents', -1000);
});

it('deletes a draft together with its adjustments', function () {
    $payableId = $this->gst->agency->payable_account_id;

    $id = $this->postJson('/api/v1/tax-returns', taxReturnPayload([
        'adjustments' => [['kind' => 'other', 'account_id' => $payableId, 'amount_cents' => 150]],
    ]), taxAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/tax-returns/{$id}", [], taxAuthHeader())->assertStatus(204);

    expect(TaxReturnAdjustment::withoutGlobalScopes()->where('tax_return_id', $id)->exists())->toBeFalse();
});

it('rejects an adjustment with a zero amount or another company’s account', function () {
    $foreignAccount = Account::withoutGlobalScopes()
        ->where('company_id', Company::factory()->create()->id)
        ->firstOrFail();

    $this->postJson('/api/v1/tax-returns', taxReturnPayload([
        'adjustments' => [['kind' => 'other', 'account_id' => $foreignAccount->id, 'amount_cents' => 0]],
    ]), taxAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['adjustments.0.account_id', 'adjustments.0.amount_cents']);
});
