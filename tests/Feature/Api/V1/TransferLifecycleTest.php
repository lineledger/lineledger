<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\TransferStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Transfer;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->savings = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function transferAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function transferPayload(array $overrides = []): array
{
    return array_merge([
        'from_account_id' => test()->bank->id,
        'to_account_id' => test()->savings->id,
        'transfer_date' => '2026-05-20',
        'from_amount_cents' => 25000,
        'to_amount_cents' => 25000,
    ], $overrides);
}

it('lists transfers with pagination meta', function () {
    $this->postJson('/api/v1/transfers', transferPayload(), transferAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/transfers', transferAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single posted transfer', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(), transferAuthHeader())->json('data.id');

    $this->getJson("/api/v1/transfers/{$id}", transferAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.from_amount_cents', 25000);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/transfers', transferPayload(['post' => false]), transferAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(['post' => false]), transferAuthHeader())->json('data.id');

    $this->postJson("/api/v1/transfers/{$id}/post", [], transferAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Transfer::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(['post' => false]), transferAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/transfers/{$id}", transferPayload([
        'memo' => 'Updated memo',
        'from_amount_cents' => 9900,
        'to_amount_cents' => 9900,
    ]), transferAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.from_amount_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('returns 409 when updating a posted transfer', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(), transferAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/transfers/{$id}", transferPayload(), transferAuthHeader())
        ->assertStatus(409);
});

it('voids a posted transfer and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(), transferAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/transfers/{$id}", [], transferAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Transfer::withoutGlobalScopes()->find($id)->status)->toBe(TransferStatus::Void);
});

it('deletes a draft transfer', function () {
    $id = $this->postJson('/api/v1/transfers', transferPayload(['post' => false]), transferAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/transfers/{$id}", [], transferAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/transfers/{$id}", transferAuthHeader())->assertStatus(404);
});

it('rejects a transfer between the same account', function () {
    $this->postJson('/api/v1/transfers', transferPayload([
        'to_account_id' => test()->bank->id,
    ]), transferAuthHeader())->assertStatus(422);
});

it('forbids writes with a banking:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['banking:read']);

    $this->getJson('/api/v1/transfers', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/transfers', transferPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
