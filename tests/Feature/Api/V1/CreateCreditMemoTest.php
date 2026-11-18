<?php

use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\JournalLine;

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

it('credits an employee contact as the sales rep', function () {
    app()->instance('current_company', $this->company);
    $rep = Contact::create(['display_name' => 'Annika Anderson', 'is_employee' => true]);
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'sales_rep_id' => $rep->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 3000, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.sales_rep_id', $rep->id);

    expect(CreditMemo::query()->withoutGlobalScopes()->firstOrFail()->sales_rep_id)->toBe($rep->id);
});

it('rejects a credit-memo sales rep that is not an employee contact', function () {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'sales_rep_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 3000, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sales_rep_id']);

    expect(CreditMemo::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepts a negative quantity and posts that line as a credit to its account', function () {
    app()->instance('current_company', $this->company);
    $accounts = Account::query()->whereIn('code', ['4000', '4100', '4900', '2200'])->get()->keyBy('code');
    app()->forgetInstance('current_company');

    $response = $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [
            ['description' => 'Premium service', 'quantity' => '1', 'unit_price_cents' => 99500, 'account_id' => $accounts['4000']->id],
            ['description' => 'Standard service', 'quantity' => '-1', 'unit_price_cents' => 19500, 'account_id' => $accounts['4100']->id],
            ['description' => 'GST', 'quantity' => '1', 'unit_price_cents' => 4000, 'account_id' => $accounts['2200']->id],
            ['description' => 'Card processing fee', 'quantity' => '1', 'unit_price_cents' => 2016, 'account_id' => $accounts['4900']->id],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_cents', 86016);

    $standard = collect($response->json('data.lines'))->firstWhere('description', 'Standard service');
    expect($standard['quantity'])->toBe('-1.0000')
        ->and($standard['line_subtotal_cents'])->toBe(-19500);

    $memo = CreditMemo::query()->withoutGlobalScopes()->firstOrFail();
    $lines = JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $memo->journal_entry_id)->get();
    $recharge = $lines->firstWhere('account_id', $accounts['4100']->id);

    expect((int) $recharge->credit_cents)->toBe(19500)
        ->and((int) $recharge->debit_cents)->toBe(0)
        ->and((int) $lines->firstWhere('account_id', $accounts['4000']->id)->debit_cents)->toBe(99500)
        ->and((int) $lines->sum('debit_cents'))->toBe((int) $lines->sum('credit_cents'));
});

it('rejects a zero quantity on a credit memo line', function (string|int $quantity) {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [
            ['quantity' => '1', 'unit_price_cents' => 3000, 'account_id' => $this->income->id],
            ['quantity' => $quantity, 'unit_price_cents' => 1000, 'account_id' => $this->income->id],
        ],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.1.quantity'])
        ->assertJsonMissingValidationErrors(['lines']);

    expect(CreditMemo::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'integer zero' => [0],
    'string zero' => ['0'],
    'two decimals' => ['0.00'],
    'negative zero' => ['-0.0'],
    'below four decimals' => ['0.00004'],
]);

it('rejects a credit memo whose lines net to zero or less, draft or not', function (array $quantities, bool $post) {
    $this->postJson('/api/v1/credit-memos', [
        'post' => $post,
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => array_map(fn (string $quantity) => ['quantity' => $quantity, 'unit_price_cents' => 1000, 'account_id' => $this->income->id], $quantities),
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines' => 'The credit memo total must be greater than zero.']);

    expect(CreditMemo::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'nets to a debit' => [['1', '-1.5'], true],
    'nets to zero' => [['2', '-2'], true],
    'a lone negative line' => [['-1'], true],
    'a negative draft' => [['1', '-3'], false],
]);

it('refuses a zero-priced credit memo with a validation error', function () {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [['quantity' => '1', 'unit_price_cents' => 0, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});

it('keeps posting an all-positive credit memo as debits to income', function () {
    $this->postJson('/api/v1/credit-memos', [
        'contact_id' => $this->customer->id,
        'credit_memo_date' => '2026-05-20',
        'lines' => [['quantity' => '2', 'unit_price_cents' => 5000, 'account_id' => $this->income->id]],
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.total_cents', 10000);

    $memo = CreditMemo::query()->withoutGlobalScopes()->firstOrFail();
    $income = JournalLine::query()->withoutGlobalScopes()
        ->where('journal_entry_id', $memo->journal_entry_id)
        ->where('account_id', $this->income->id)
        ->sole();

    expect((int) $income->debit_cents)->toBe(10000)
        ->and((int) $income->credit_cents)->toBe(0);
});
