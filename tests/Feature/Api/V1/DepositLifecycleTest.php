<?php

use App\Enums\AccountSubtype;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Deposit;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function depositAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function depositPayload(array $overrides = []): array
{
    return array_merge([
        'bank_account_id' => test()->bank->id,
        'deposit_date' => '2026-05-20',
        'lines' => [[
            'account_id' => test()->income->id,
            'description' => 'Owner contribution',
            'amount_cents' => 25000,
        ]],
    ], $overrides);
}

it('lists deposits with pagination meta', function () {
    $this->postJson('/api/v1/deposits', depositPayload(), depositAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/deposits', depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single deposit', function () {
    $id = $this->postJson('/api/v1/deposits', depositPayload(), depositAuthHeader())->json('data.id');

    $this->getJson("/api/v1/deposits/{$id}", depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 25000);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/deposits', depositPayload(['post' => false]), depositAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/deposits', depositPayload(['post' => false]), depositAuthHeader())->json('data.id');

    $this->postJson("/api/v1/deposits/{$id}/post", [], depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Deposit::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/deposits', depositPayload(['post' => false]), depositAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/deposits/{$id}", depositPayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'account_id' => $this->income->id, 'amount_cents' => 9900,
        ]],
    ]), depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.amount_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted deposit via update, reusing its journal entry', function () {
    $created = $this->postJson('/api/v1/deposits', depositPayload(), depositAuthHeader())->json('data');
    $id = $created['id'];

    $this->patchJson("/api/v1/deposits/{$id}", depositPayload([
        'memo' => 'Reposted',
        'lines' => [['account_id' => $this->income->id, 'amount_cents' => 30000]],
    ]), depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Reposted')
        ->assertJsonPath('data.amount_cents', 30000)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.journal_entry_id', $created['journal_entry_id']);

    expect(Deposit::withoutGlobalScopes()->find($id)->amount_cents)->toBe(30000);
});

it('voids a posted deposit and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/deposits', depositPayload(), depositAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/deposits/{$id}", [], depositAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Deposit::withoutGlobalScopes()->find($id)->status)->toBe(DepositStatus::Void);
});

it('deletes a draft deposit', function () {
    $id = $this->postJson('/api/v1/deposits', depositPayload(['post' => false]), depositAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/deposits/{$id}", [], depositAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/deposits/{$id}", depositAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s deposit', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/deposits', depositPayload(), depositAuthHeader())->json('data.id');

    $this->getJson("/api/v1/deposits/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a banking:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['banking:read']);

    $this->getJson('/api/v1/deposits', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/deposits', depositPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
