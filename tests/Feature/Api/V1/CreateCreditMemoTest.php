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

it('creates and posts a credit memo in a single call', function () {
    $response = $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Refund',
            'quantity' => '1',
            'unit_price_cents' => 3000,
            'account_id' => $this->income->id,
        ]],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 3000);

    $memo = CreditMemo::query()->withoutGlobalScopes()->firstOrFail();
    expect($memo->status)->toBe(CreditMemoStatus::Posted);
    expect($memo->credit_memo_no)->toStartWith('CM-');
    expect($memo->journal_entry_id)->not->toBeNull();
});

it('requires at least one line', function () {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});
