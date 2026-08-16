<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->fixedAsset = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', '!=', 'Accumulated Depreciation')
        ->orderBy('code')
        ->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function assetAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function assetPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Delivery van',
        'asset_account_id' => test()->fixedAsset->id,
        'acquired_date' => '2026-01-15',
        'cost_cents' => 4500000,
    ], $overrides);
}

it('lists assets with pagination meta', function () {
    $this->postJson('/api/v1/assets', assetPayload(), assetAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/assets', assetAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('creates an asset with an auto-generated number', function () {
    $response = $this->postJson('/api/v1/assets', assetPayload(['cost_cents' => 100000]), assetAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Delivery van')
        ->assertJsonPath('data.cost_cents', 100000)
        ->assertJsonPath('data.status', 'in-service');

    expect($response->json('data.asset_no'))->toStartWith('AST-');
});

it('shows a single asset', function () {
    $id = $this->postJson('/api/v1/assets', assetPayload(), assetAuthHeader())->json('data.id');

    $this->getJson("/api/v1/assets/{$id}", assetAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);
});

it('updates an asset', function () {
    $id = $this->postJson('/api/v1/assets', assetPayload(), assetAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/assets/{$id}", assetPayload([
        'name' => 'Cargo van',
        'cost_cents' => 5000000,
    ]), assetAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Cargo van')
        ->assertJsonPath('data.cost_cents', 5000000);
});

it('requires an asset account', function () {
    $payload = assetPayload();
    unset($payload['asset_account_id']);

    $this->postJson('/api/v1/assets', $payload, assetAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('asset_account_id');
});

it('deletes an asset', function () {
    $id = $this->postJson('/api/v1/assets', assetPayload(), assetAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/assets/{$id}", [], assetAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/assets/{$id}", assetAuthHeader())->assertStatus(404);
    expect(Asset::withoutGlobalScopes()->find($id)->trashed())->toBeTrue();
});

it('returns 404 for another company\'s asset', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/assets', assetPayload(), assetAuthHeader())->json('data.id');

    $this->getJson("/api/v1/assets/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['accounting:read']);

    $this->getJson('/api/v1/assets', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/assets', assetPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
