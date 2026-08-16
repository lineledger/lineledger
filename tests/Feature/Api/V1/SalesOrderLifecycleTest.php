<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;

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

function soAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function salesOrderPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'order_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Widget',
            'quantity' => '10',
            'unit_price_cents' => 5000,
            'account_id' => test()->income->id,
        ]],
    ], $overrides);
}

it('creates a sales order in open status', function () {
    $response = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.total_cents', 50000);

    expect($response->json('data.lines.0.qty_ordered'))->toBe('10.0000')
        ->and($response->json('data.lines.0.qty_backordered'))->toBe('10.0000');
});

it('lists sales orders with pagination meta', function () {
    $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/sales-orders', soAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('fulfills a sales order into a draft invoice and flips it to partial', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');
    $lineId = $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())->json('data.lines.0.id');

    $this->postJson("/api/v1/sales-orders/{$id}/fulfill", ['lines' => [$lineId => 4]], soAuthHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.sales_order_id', $id);

    $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'partial')
        ->assertJsonPath('data.lines.0.qty_backordered', '6.0000');
});

it('rejects over-fulfilment with a 422', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');
    $lineId = $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())->json('data.lines.0.id');

    $this->postJson("/api/v1/sales-orders/{$id}/fulfill", ['lines' => [$lineId => 99]], soAuthHeader())
        ->assertStatus(422);
});

it('cancels a sales order', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');

    $this->postJson("/api/v1/sales-orders/{$id}/cancel", [], soAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');
});

it('blocks editing an order that has been invoiced', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');
    $lineId = $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())->json('data.lines.0.id');
    $this->postJson("/api/v1/sales-orders/{$id}/fulfill", ['lines' => [$lineId => 4]], soAuthHeader())->assertStatus(201);

    $this->patchJson("/api/v1/sales-orders/{$id}", salesOrderPayload(['memo' => 'Nope']), soAuthHeader())
        ->assertStatus(409);
});

it('edits an open order via update', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/sales-orders/{$id}", salesOrderPayload([
        'memo' => 'Updated',
        'lines' => [['quantity' => '2', 'unit_price_cents' => 9900, 'account_id' => $this->income->id]],
    ]), soAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated')
        ->assertJsonPath('data.total_cents', 19800);
});

it('deletes an order with no invoices', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/sales-orders/{$id}", [], soAuthHeader())->assertStatus(204);
    $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())->assertStatus(404);
});

it('blocks deleting an order that has invoices', function () {
    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');
    $lineId = $this->getJson("/api/v1/sales-orders/{$id}", soAuthHeader())->json('data.lines.0.id');
    $this->postJson("/api/v1/sales-orders/{$id}/fulfill", ['lines' => [$lineId => 4]], soAuthHeader())->assertStatus(201);

    $this->deleteJson("/api/v1/sales-orders/{$id}", [], soAuthHeader())->assertStatus(409);
});

it('returns 404 for another company\'s sales order', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/sales-orders', salesOrderPayload(), soAuthHeader())->json('data.id');

    $this->getJson("/api/v1/sales-orders/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    $this->getJson('/api/v1/sales-orders', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);
    $this->postJson('/api/v1/sales-orders', salesOrderPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
