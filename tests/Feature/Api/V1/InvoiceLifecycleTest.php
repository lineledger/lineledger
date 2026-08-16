<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;

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

function authHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function invoicePayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Consulting',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'account_id' => test()->income->id,
        ]],
    ], $overrides);
}

it('lists invoices with pagination meta', function () {
    $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->assertStatus(201);

    $this->getJson('/api/v1/invoices', authHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single invoice', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->getJson("/api/v1/invoices/{$id}", authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted');
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->postJson("/api/v1/invoices/{$id}/post", [], authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Invoice::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 9900, 'account_id' => $this->income->id,
        ]],
    ]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.total_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted invoice in place via update', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 12300, 'account_id' => $this->income->id,
        ]],
    ]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.total_cents', 12300)
        ->assertJsonPath('data.status', 'posted');
});

it('voids a posted invoice and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->deleteJson("/api/v1/invoices/{$id}", [], authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Invoice::withoutGlobalScopes()->find($id)->status)->toBe(InvoiceStatus::Void);
});

it('deletes a draft invoice', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->deleteJson("/api/v1/invoices/{$id}", [], authHeader())->assertStatus(204);

    // Soft-deleted: no longer retrievable through the API.
    $this->getJson("/api/v1/invoices/{$id}", authHeader())->assertStatus(404);
});

it('returns 404 for another company\'s invoice', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->getJson("/api/v1/invoices/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    // read works
    $this->getJson('/api/v1/invoices', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    // write is forbidden
    $this->postJson('/api/v1/invoices', invoicePayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

it('allows writes with a sales:write key', function () {
    ['plaintext' => $writePlain] = CompanyApiKey::mint($this->company, 'Write', null, ['sales:write']);

    $this->postJson('/api/v1/invoices', invoicePayload(), ['Authorization' => "Bearer {$writePlain}"])
        ->assertStatus(201);
});
