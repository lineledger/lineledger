<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Deposit;
use App\Models\JournalEntry;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->debitAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->creditAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function journalPayload(array $overrides = []): array
{
    return array_merge([
        'entry_date' => '2026-05-20',
        'memo' => 'Test entry',
        'lines' => [
            ['account_id' => test()->debitAccount->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => test()->creditAccount->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $overrides);
}

it('lists journal entries with pagination meta', function () {
    $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->assertStatus(201);

    $this->getJson('/api/v1/journal-entries', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single journal entry', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/journal-entries/{$id}", $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted');
});

it('posts by default when storing', function () {
    $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.is_posted', true);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/journal-entries', journalPayload(['post' => false]), $this->h);

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.is_posted'))->toBeFalse();
});

it('auto-generates an entry number when omitted', function () {
    $no = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.entry_no');

    expect($no)->toStartWith('JE-');
});

it('rejects an unbalanced entry with 422', function () {
    $this->postJson('/api/v1/journal-entries', journalPayload([
        'lines' => [
            ['account_id' => $this->debitAccount->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->creditAccount->id, 'debit_cents' => 0, 'credit_cents' => 4000],
        ],
    ]), $this->h)->assertStatus(422);
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(['post' => false]), $this->h)->json('data.id');

    $this->patchJson("/api/v1/journal-entries/{$id}", journalPayload([
        'memo' => 'Updated memo',
        'lines' => [
            ['account_id' => $this->debitAccount->id, 'debit_cents' => 9900, 'credit_cents' => 0],
            ['account_id' => $this->creditAccount->id, 'debit_cents' => 0, 'credit_cents' => 9900],
        ],
    ]), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.total_debits_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('overwrites a posted entry in place via update', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $this->patchJson("/api/v1/journal-entries/{$id}", journalPayload([
        'lines' => [
            ['account_id' => $this->debitAccount->id, 'debit_cents' => 12300, 'credit_cents' => 0],
            ['account_id' => $this->creditAccount->id, 'debit_cents' => 0, 'credit_cents' => 12300],
        ],
    ]), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.total_debits_cents', 12300)
        ->assertJsonPath('data.status', 'posted');
});

it('rejects editing a source-linked entry with 409', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $entry = JournalEntry::withoutGlobalScopes()->find($id);
    $entry->update(['source_type' => Deposit::class, 'source_id' => 1]);

    $this->patchJson("/api/v1/journal-entries/{$id}", journalPayload([
        'memo' => 'should not save',
    ]), $this->h)->assertStatus(409);

    expect(JournalEntry::withoutGlobalScopes()->find($id)->memo)->toBe('Test entry');
});

it('rejects overwriting a posted entry into an unbalanced state with 422', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $this->patchJson("/api/v1/journal-entries/{$id}", journalPayload([
        'lines' => [
            ['account_id' => $this->debitAccount->id, 'debit_cents' => 12300, 'credit_cents' => 0],
            ['account_id' => $this->creditAccount->id, 'debit_cents' => 0, 'credit_cents' => 99],
        ],
    ]), $this->h)->assertStatus(422);
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(['post' => false]), $this->h)->json('data.id');

    $this->postJson("/api/v1/journal-entries/{$id}/post", [], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(JournalEntry::withoutGlobalScopes()->find($id)->is_posted)->toBeTrue();
});

it('voids a posted entry and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $this->deleteJson("/api/v1/journal-entries/{$id}", [], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(JournalEntry::withoutGlobalScopes()->find($id)->voided_at)->not->toBeNull();
});

it('deletes a draft entry', function () {
    $id = $this->postJson('/api/v1/journal-entries', journalPayload(['post' => false]), $this->h)->json('data.id');

    $this->deleteJson("/api/v1/journal-entries/{$id}", [], $this->h)->assertStatus(204);
});

it('returns 404 for another company\'s entry', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/journal-entries', journalPayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/journal-entries/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with an accounting:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['accounting:read']);

    $this->getJson('/api/v1/journal-entries', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/journal-entries', journalPayload(), ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
