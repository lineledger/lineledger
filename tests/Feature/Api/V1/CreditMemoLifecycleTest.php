<?php

use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CreditMemo;

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

function creditMemoAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function creditMemoPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Refund',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'account_id' => test()->income->id,
        ]],
    ], $overrides);
}

it('lists credit memos with pagination meta', function () {
    $this->postJson('/api/v1/credit-memos', creditMemoPayload(), creditMemoAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/credit-memos', creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single credit memo', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(), creditMemoAuthHeader())->json('data.id');

    $this->getJson("/api/v1/credit-memos/{$id}", creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted');
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/credit-memos', creditMemoPayload(['post' => false]), creditMemoAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(['post' => false]), creditMemoAuthHeader())->json('data.id');

    $this->postJson("/api/v1/credit-memos/{$id}/post", [], creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(CreditMemo::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(['post' => false]), creditMemoAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/credit-memos/{$id}", creditMemoPayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 9900, 'account_id' => $this->income->id,
        ]],
    ]), creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.total_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted credit memo in place via update', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(), creditMemoAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/credit-memos/{$id}", creditMemoPayload([
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 12300, 'account_id' => $this->income->id,
        ]],
    ]), creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.total_cents', 12300)
        ->assertJsonPath('data.status', 'posted');
});

it('voids a posted credit memo and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(), creditMemoAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/credit-memos/{$id}", [], creditMemoAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(CreditMemo::withoutGlobalScopes()->find($id)->status)->toBe(CreditMemoStatus::Void);
});

it('deletes a draft credit memo', function () {
    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(['post' => false]), creditMemoAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/credit-memos/{$id}", [], creditMemoAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/credit-memos/{$id}", creditMemoAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s credit memo', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/credit-memos', creditMemoPayload(), creditMemoAuthHeader())->json('data.id');

    $this->getJson("/api/v1/credit-memos/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    $this->getJson('/api/v1/credit-memos', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/credit-memos', creditMemoPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

it('allows writes with a sales:write key', function () {
    ['plaintext' => $writePlain] = CompanyApiKey::mint($this->company, 'Write', null, ['sales:write']);

    $this->postJson('/api/v1/credit-memos', creditMemoPayload(), ['Authorization' => "Bearer {$writePlain}"])
        ->assertStatus(201);
});
