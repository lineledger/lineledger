<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\JournalEntry;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function accountPayload(array $overrides = []): array
{
    return array_merge([
        'code' => '7777',
        'name' => 'Consulting Income',
        'subtype' => AccountSubtype::Income->value,
    ], $overrides);
}

it('lists accounts with pagination meta', function () {
    $this->getJson('/api/v1/accounts', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta']);

    expect($this->getJson('/api/v1/accounts', $this->h)->json('meta.total'))->toBeGreaterThan(0);
});

it('shows a single account', function () {
    app()->instance('current_company', $this->company);
    $account = Account::query()->first();
    app()->forgetInstance('current_company');

    $this->getJson("/api/v1/accounts/{$account->id}", $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.id', $account->id);
});

it('creates an account', function () {
    $this->postJson('/api/v1/accounts', accountPayload(), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.code', '7777')
        ->assertJsonPath('data.type', 'income')
        ->assertJsonPath('data.normal_balance', 'credit');
});

it('updates an account', function () {
    $id = $this->postJson('/api/v1/accounts', accountPayload(), $this->h)->json('data.id');

    $this->patchJson("/api/v1/accounts/{$id}", accountPayload(['name' => 'Renamed']), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Renamed');
});

it('rejects a parent account of a different type', function () {
    app()->instance('current_company', $this->company);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/accounts', accountPayload([
        'subtype' => AccountSubtype::Expense->value,
        'parent_id' => $bank->id,
    ]), $this->h)
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('round-trips a currency_code on a bank account under multi-currency', function () {
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/accounts', accountPayload([
        'code' => '1015',
        'name' => 'USD Chequing',
        'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.currency_code', 'USD');
});

it('deletes an account with no transactions', function () {
    $id = $this->postJson('/api/v1/accounts', accountPayload(), $this->h)->json('data.id');

    $this->deleteJson("/api/v1/accounts/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/accounts/{$id}", $this->h)->assertStatus(404);
});

it('allows changing a system account code and persists it', function () {
    app()->instance('current_company', $this->company);
    $system = Account::query()->where('is_system', true)->first();
    app()->forgetInstance('current_company');

    expect($system)->not->toBeNull();

    $this->patchJson("/api/v1/accounts/{$system->id}", [
        'code' => 'CHANGED',
        'name' => $system->name,
        'subtype' => $system->subtype->value,
    ], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.code', 'CHANGED');

    expect($system->fresh()->code)->toBe('CHANGED');
});

it('rejects changing a system account subtype with 422', function () {
    app()->instance('current_company', $this->company);
    $system = Account::query()->where('is_system', true)->first();
    app()->forgetInstance('current_company');

    expect($system)->not->toBeNull();

    // Pick any subtype other than the account's current one.
    $otherSubtype = collect(AccountSubtype::cases())
        ->first(fn (AccountSubtype $subtype): bool => $subtype !== $system->subtype);

    $this->patchJson("/api/v1/accounts/{$system->id}", [
        'code' => $system->code,
        'name' => $system->name,
        'subtype' => $otherSubtype->value,
    ], $this->h)
        ->assertStatus(422)
        ->assertJsonPath('message', 'A system account\'s type cannot be changed.');

    expect($system->fresh()->subtype)->toBe($system->subtype);
});

it('allows renaming a system account without touching code or subtype', function () {
    app()->instance('current_company', $this->company);
    $system = Account::query()->where('is_system', true)->first();
    app()->forgetInstance('current_company');

    $this->patchJson("/api/v1/accounts/{$system->id}", [
        'code' => $system->code,
        'name' => 'New Display Name',
        'subtype' => $system->subtype->value,
    ], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'New Display Name');
});

it('round-trips a cash-flow activity override via the API', function () {
    $id = $this->postJson('/api/v1/accounts', accountPayload([
        'code' => '1850',
        'name' => 'Equipment',
        'subtype' => AccountSubtype::FixedAsset->value,
        'cash_flow_activity' => 'operating',
    ]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.cash_flow_activity', 'operating')
        ->json('data.id');

    $this->getJson("/api/v1/accounts/{$id}", $this->h)
        ->assertJsonPath('data.cash_flow_activity', 'operating');
});

it('refuses to delete an account with journal lines (conflict)', function () {
    app()->instance('current_company', $this->company);
    $account = Account::query()->first();
    $entry = JournalEntry::create([
        'entry_no' => 'JE-TEST-1',
        'entry_date' => '2026-05-20',
    ]);
    $entry->lines()->create([
        'account_id' => $account->id,
        'debit_cents' => 1000,
        'credit_cents' => 0,
        'line_order' => 0,
    ]);
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/accounts/{$account->id}", [], $this->h)->assertStatus(409);
});

it('returns 404 for another company\'s account', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/accounts', accountPayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/accounts/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with an accounting:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['accounting:read']);

    $this->getJson('/api/v1/accounts', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/accounts', accountPayload(), ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
