<?php

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    // A posted vendor bill to pay against.
    $this->bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-1',
        'bill_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => BillStatus::Draft,
    ]);
    $this->bill->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Stuff',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    $this->bill->refresh();
    $this->bill->recalculateTotals();
    app(BillPoster::class)->post($this->bill);

    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function billPaymentAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function billPaymentPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->vendor->id,
        'payment_date' => '2026-05-20',
        'paid_from_account_id' => test()->bank->id,
        'amount_cents' => 4000,
        'applications' => [[
            'bill_id' => test()->bill->id,
            'amount_cents' => 4000,
        ]],
    ], $overrides);
}

it('lists bill payments with pagination meta', function () {
    $this->postJson('/api/v1/bill-payments', billPaymentPayload(), billPaymentAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/bill-payments', billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single bill payment', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(), billPaymentAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bill-payments/{$id}", billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.amount_cents', 4000);
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/bill-payments', billPaymentPayload(['post' => false]), billPaymentAuthHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action and applies to the bill', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(['post' => false]), billPaymentAuthHeader())->json('data.id');

    $this->postJson("/api/v1/bill-payments/{$id}/post", [], billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Bill::withoutGlobalScopes()->find($this->bill->id)->status)->toBe(BillStatus::Partial);
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(['post' => false]), billPaymentAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/bill-payments/{$id}", billPaymentPayload([
        'memo' => 'Updated',
        'amount_cents' => 6000,
        'applications' => [['bill_id' => $this->bill->id, 'amount_cents' => 6000]],
    ]), billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated')
        ->assertJsonPath('data.amount_cents', 6000)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted payment in place via update', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(), billPaymentAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/bill-payments/{$id}", billPaymentPayload([
        'amount_cents' => 7000,
        'applications' => [['bill_id' => $this->bill->id, 'amount_cents' => 7000]],
    ]), billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.amount_cents', 7000)
        ->assertJsonPath('data.status', 'posted');
});

it('rejects applications exceeding the payment amount', function () {
    $this->postJson('/api/v1/bill-payments', billPaymentPayload([
        'amount_cents' => 1000,
        'applications' => [['bill_id' => $this->bill->id, 'amount_cents' => 5000]],
    ]), billPaymentAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('applications');
});

it('voids a posted payment and restores the bill balance', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(), billPaymentAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/bill-payments/{$id}", [], billPaymentAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(BillPayment::withoutGlobalScopes()->find($id)->status)->toBe(BillPaymentStatus::Void);
    expect(Bill::withoutGlobalScopes()->find($this->bill->id)->status)->toBe(BillStatus::Posted);
});

it('deletes a draft payment', function () {
    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(['post' => false]), billPaymentAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/bill-payments/{$id}", [], billPaymentAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/bill-payments/{$id}", billPaymentAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s payment', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/bill-payments', billPaymentPayload(), billPaymentAuthHeader())->json('data.id');

    $this->getJson("/api/v1/bill-payments/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a purchases:read key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['purchases:read']);

    $this->getJson('/api/v1/bill-payments', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/bill-payments', billPaymentPayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
