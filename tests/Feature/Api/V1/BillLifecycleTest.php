<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    $this->employee = Contact::create(['display_name' => 'Jane Staff', 'is_employee' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function billAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function billPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->vendor->id,
        'bill_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Widgets',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'account_id' => test()->expense->id,
        ]],
    ], $overrides);
}

it('lists bills with pagination meta', function () {
    $this->postJson('/api/v1/bills', billPayload(), billAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/bills', billAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single bill', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(), billAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bills/{$id}", billAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 10000);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/bills', billPayload(['post' => false]), billAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('accepts a reimbursement bill_type with an employee contact', function () {
    $response = $this->postJson('/api/v1/bills', billPayload([
        'bill_type' => 'reimbursement',
        'contact_id' => $this->employee->id,
        'post' => false,
    ]), billAuthHeader());

    $response->assertStatus(201)
        ->assertJsonPath('data.bill_type', 'reimbursement')
        ->assertJsonPath('data.contact_id', $this->employee->id);
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(['post' => false]), billAuthHeader())->json('data.id');

    $this->postJson("/api/v1/bills/{$id}/post", [], billAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Bill::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(['post' => false]), billAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/bills/{$id}", billPayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 9900, 'account_id' => $this->expense->id,
        ]],
    ]), billAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.total_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted bill in place via update', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(), billAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/bills/{$id}", billPayload([
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 12300, 'account_id' => $this->expense->id,
        ]],
    ]), billAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.total_cents', 12300)
        ->assertJsonPath('data.status', 'posted');
});

it('voids a posted bill and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(), billAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/bills/{$id}", [], billAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Bill::withoutGlobalScopes()->find($id)->status)->toBe(BillStatus::Void);
});

it('deletes a draft bill', function () {
    $id = $this->postJson('/api/v1/bills', billPayload(['post' => false]), billAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/bills/{$id}", [], billAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/bills/{$id}", billAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s bill', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/bills', billPayload(), billAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bills/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a purchases:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['purchases:read']);

    $this->getJson('/api/v1/bills', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/bills', billPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

it('allows writes with a purchases:write key', function () {
    ['plaintext' => $writePlain] = CompanyApiKey::mint($this->company, 'Write', null, ['purchases:write']);

    $this->postJson('/api/v1/bills', billPayload(), ['Authorization' => "Bearer {$writePlain}"])
        ->assertStatus(201);
});
