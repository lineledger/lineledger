<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function chequeAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function chequePayload(array $overrides = []): array
{
    return array_merge([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => '2026-05-20',
        'payee_name' => 'Hydro One',
        'lines' => [[
            'account_id' => test()->expense->id,
            'description' => 'Utilities',
            'amount_cents' => 7500,
        ]],
    ], $overrides);
}

it('lists cheques with pagination meta', function () {
    $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/cheques', chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->getJson("/api/v1/cheques/{$id}", chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 7500);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->postJson("/api/v1/cheques/{$id}/post", [], chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Cheque::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/cheques/{$id}", chequePayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'account_id' => $this->expense->id, 'amount_cents' => 9900,
        ]],
    ]), chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.amount_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('returns 409 when updating a posted cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/cheques/{$id}", chequePayload([
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 100]],
    ]), chequeAuthHeader())
        ->assertStatus(409);
});

it('voids a posted cheque and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/cheques/{$id}", [], chequeAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Cheque::withoutGlobalScopes()->find($id)->status)->toBe(ChequeStatus::Void);
});

it('deletes a draft cheque', function () {
    $id = $this->postJson('/api/v1/cheques', chequePayload(['post' => false]), chequeAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/cheques/{$id}", [], chequeAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/cheques/{$id}", chequeAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s cheque', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/cheques', chequePayload(), chequeAuthHeader())->json('data.id');

    $this->getJson("/api/v1/cheques/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a banking:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['banking:read']);

    $this->getJson('/api/v1/cheques', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/cheques', chequePayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
