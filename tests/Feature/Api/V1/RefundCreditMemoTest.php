<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function makeCreditMemo(int $amountCents, bool $post = true): int
{
    return test()->postJson('/api/v1/credit-memos', [
        'contact_id' => test()->customer->id,
        'credit_memo_date' => '2026-06-12',
        'post' => $post,
        'lines' => [[
            'description' => 'refund excess flight cost',
            'quantity' => '1',
            'unit_price_cents' => $amountCents,
            'account_id' => test()->income->id,
        ]],
    ], ['Authorization' => 'Bearer '.test()->plain])->assertStatus(201)->json('data.id');
}

it('refunds a credit memo as a posted negative receipt', function () {
    $memoId = makeCreditMemo(13442);

    $response = $this->postJson("/api/v1/credit-memos/{$memoId}/refund", [
        'refund_date' => '2026-07-02',
        'amount_cents' => 13442,
        'deposit_to_account_id' => $this->undeposited->id,
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201);

    $receipt = CustomerReceipt::query()->withoutGlobalScopes()->firstOrFail();
    expect($receipt->amount_cents)->toBe(-13442);
    expect((int) $receipt->credit_memo_id)->toBe($memoId);
    expect($receipt->journal_entry_id)->not->toBeNull();
    expect($receipt->receipt_no)->toStartWith('REC-');
});

it('rejects a refund exceeding the remaining refundable amount', function () {
    $memoId = makeCreditMemo(5000);

    $this->postJson("/api/v1/credit-memos/{$memoId}/refund", [
        'refund_date' => '2026-07-02',
        'amount_cents' => 9999,
        'deposit_to_account_id' => $this->undeposited->id,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount_cents']);
});

it('rejects a deposit account that is not bank or undeposited funds', function () {
    $memoId = makeCreditMemo(5000);

    $this->postJson("/api/v1/credit-memos/{$memoId}/refund", [
        'refund_date' => '2026-07-02',
        'amount_cents' => 1000,
        'deposit_to_account_id' => $this->income->id,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['deposit_to_account_id']);
});

it('rejects refunding a draft (unposted) credit memo', function () {
    $memoId = makeCreditMemo(5000, post: false);

    $this->postJson("/api/v1/credit-memos/{$memoId}/refund", [
        'refund_date' => '2026-07-02',
        'amount_cents' => 5000,
        'deposit_to_account_id' => $this->undeposited->id,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(409);

    expect(CustomerReceipt::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('rejects refunding a voided credit memo', function () {
    $memoId = makeCreditMemo(5000);

    // Voiding a posted credit memo reverses it but leaves total_cents intact.
    $this->deleteJson("/api/v1/credit-memos/{$memoId}", [], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(200);

    $this->postJson("/api/v1/credit-memos/{$memoId}/refund", [
        'refund_date' => '2026-07-02',
        'amount_cents' => 5000,
        'deposit_to_account_id' => $this->undeposited->id,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(409);

    expect(CustomerReceipt::query()->withoutGlobalScopes()->count())->toBe(0);
});
