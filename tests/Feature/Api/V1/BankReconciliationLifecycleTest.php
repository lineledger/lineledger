<?php

use App\Enums\AccountSubtype;
use App\Enums\BankReconciliationStatus;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Deposit;
use App\Models\JournalLine;
use App\Services\Posting\DepositPoster;

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

function recAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function recPayload(array $overrides = []): array
{
    return array_merge([
        'account_id' => test()->bank->id,
        'statement_date' => '2026-05-31',
        'ending_balance_cents' => 0,
    ], $overrides);
}

/**
 * Post a deposit into the bank account so there is a clearable journal line.
 * Returns [depositId, bankJournalLineId, amountCents].
 */
function postBankDeposit(int $amountCents): array
{
    app()->instance('current_company', test()->company);

    $deposit = Deposit::create([
        'bank_account_id' => test()->bank->id,
        'deposit_no' => 'DEP-'.uniqid(),
        'deposit_date' => '2026-05-15',
        'status' => DepositStatus::Draft,
    ]);
    $deposit->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Sale',
        'amount_cents' => $amountCents,
        'line_order' => 0,
    ]);
    $deposit->refresh();
    $deposit->recalculateAmount();
    app(DepositPoster::class)->post($deposit);

    $entry = $deposit->fresh()->journalEntry;
    $bankLineId = (int) JournalLine::query()
        ->where('journal_entry_id', $entry->id)
        ->where('account_id', test()->bank->id)
        ->value('id');

    app()->forgetInstance('current_company');

    return [$deposit->id, $bankLineId, $amountCents];
}

it('lists reconciliations with pagination meta', function () {
    $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/bank-reconciliations', recAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single reconciliation', function () {
    $id = $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bank-reconciliations/{$id}", recAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'in_progress');
});

it('begins an in-progress reconciliation via store', function () {
    $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.ending_balance_cents', 0);
});

it('rejects a second in-progress reconciliation on the same account', function () {
    $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())->assertStatus(201);

    $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())
        ->assertStatus(422);
});

it('marks lines via update and completes when balanced', function () {
    [, $bankLineId, $amount] = postBankDeposit(12345);

    $id = $this->postJson('/api/v1/bank-reconciliations', recPayload([
        'ending_balance_cents' => $amount,
    ]), recAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/bank-reconciliations/{$id}", [
        'marked_line_ids' => [$bankLineId],
    ], recAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.marked_line_ids', [$bankLineId]);

    $this->postJson("/api/v1/bank-reconciliations/{$id}/complete", [], recAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    expect(BankReconciliation::withoutGlobalScopes()->find($id)->status)
        ->toBe(BankReconciliationStatus::Completed);
});

it('returns 422 when completing an out-of-balance reconciliation', function () {
    $id = $this->postJson('/api/v1/bank-reconciliations', recPayload([
        'ending_balance_cents' => 99999,
    ]), recAuthHeader())->json('data.id');

    $this->postJson("/api/v1/bank-reconciliations/{$id}/complete", [], recAuthHeader())
        ->assertStatus(422);
});

it('cancels an in-progress reconciliation via destroy', function () {
    $id = $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/bank-reconciliations/{$id}", [], recAuthHeader())->assertStatus(204);

    expect(BankReconciliation::withoutGlobalScopes()->find($id))->toBeNull();
});

it('returns 404 for another company\'s reconciliation', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/bank-reconciliations', recPayload(), recAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bank-reconciliations/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a banking:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['banking:read']);

    $this->getJson('/api/v1/bank-reconciliations', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/bank-reconciliations', recPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
