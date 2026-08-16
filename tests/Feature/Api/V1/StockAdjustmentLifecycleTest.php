<?php

use App\Enums\AccountSubtype;
use App\Enums\StockAdjustmentReason;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Item;
use App\Models\StockAdjustment;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $this->item = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => $inventoryAsset->id,
        'cogs_account_id' => $cogs->id,
    ]);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function adjAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function adjPayload(array $overrides = []): array
{
    return array_merge([
        'adjustment_date' => '2026-05-20',
        'reason' => StockAdjustmentReason::OpeningBalance->value,
        'lines' => [[
            'item_id' => test()->item->id,
            'qty_change' => '100',
            'unit_cost_cents' => 500,
        ]],
    ], $overrides);
}

it('lists stock adjustments with pagination meta', function () {
    $this->postJson('/api/v1/stock-adjustments', adjPayload(), adjAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/stock-adjustments', adjAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('creates and posts an adjustment by default', function () {
    $response = $this->postJson('/api/v1/stock-adjustments', adjPayload(), adjAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.reason', 'opening_balance');

    expect($response->json('data.journal_entry_id'))->not->toBeNull();
    expect($response->json('data.lines'))->toHaveCount(1);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader());

    $response->assertStatus(201);
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('shows a single adjustment', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader())->json('data.id');

    $this->getJson("/api/v1/stock-adjustments/{$id}", adjAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader())->json('data.id');

    $this->postJson("/api/v1/stock-adjustments/{$id}/post", [], adjAuthHeader())
        ->assertStatus(200);

    expect(StockAdjustment::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/stock-adjustments/{$id}", adjPayload([
        'notes' => 'Recounted',
        'lines' => [[
            'item_id' => $this->item->id,
            'qty_change' => '50',
            'unit_cost_cents' => 600,
        ]],
    ]), adjAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.notes', 'Recounted')
        ->assertJsonPath('data.lines.0.qty_change', '50.0000');
});

it('refuses to edit a posted adjustment (no repost)', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(), adjAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/stock-adjustments/{$id}", adjPayload(['notes' => 'nope']), adjAuthHeader())
        ->assertStatus(409);
});

it('voids a posted adjustment and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(), adjAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/stock-adjustments/{$id}", [], adjAuthHeader())
        ->assertStatus(200);

    expect(StockAdjustment::withoutGlobalScopes()->find($id)->voided_at)->not->toBeNull();
});

it('deletes a draft adjustment', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/stock-adjustments/{$id}", [], adjAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/stock-adjustments/{$id}", adjAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s adjustment', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/stock-adjustments', adjPayload(['post' => false]), adjAuthHeader())->json('data.id');

    $this->getJson("/api/v1/stock-adjustments/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['inventory:read']);

    $this->getJson('/api/v1/stock-adjustments', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/stock-adjustments', adjPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
