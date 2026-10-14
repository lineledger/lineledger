<?php

use App\Actions\Banking\SaveDeposit;
use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Posting\DepositPoster;
use Livewire\Livewire;

/*
 * A card refund is a negative receipt in Undeposited Funds. Batched with the
 * day's card receipts it nets out; on its own — or when refunds outweigh the
 * receipts — the deposit's total is negative and it posts as money OUT of the
 * bank, matching the processor's debit on the statement.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    // A family's overpayment, refunded by card: a -250.00 receipt in Undeposited Funds.
    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Nguyen Family']);
    $this->payment = overpaymentReceipt($this->customer, 25000);
    $this->refund = app(RecordCustomerRefund::class)->handle($this->payment, [
        'method' => 'card',
        'amount_cents' => 25000,
        'refund_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function refundOnlyDeposit(): Deposit
{
    return app(SaveDeposit::class)->handle([
        'bank_account_id' => test()->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [['customer_receipt_id' => test()->refund->id]],
    ]);
}

it('posts a refund-only deposit as money out of the bank', function () {
    $deposit = refundOnlyDeposit();
    $entry = app(DepositPoster::class)->post($deposit)->load('lines');

    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    $undepLine = $entry->lines->firstWhere('account_id', $this->undeposited->id);

    expect($deposit->fresh()->amount_cents)->toBe(-25000)
        ->and($deposit->fresh()->status)->toBe(DepositStatus::Posted)
        ->and($entry->isBalanced())->toBeTrue()
        // A credit to the bank is what the reconcile page lists under
        // Cheques and Payments, against the processor's debit.
        ->and($bankLine->credit_cents)->toBe(25000)
        ->and($bankLine->debit_cents)->toBe(0)
        ->and($undepLine->debit_cents)->toBe(25000)
        ->and($this->bank->fresh()->balance_cents)->toBe(-25000);

    // Undeposited Funds holds just the cheque payment again.
    expect($this->undeposited->fresh()->balance_cents)->toBe(25000);
});

it('still nets a refund into a larger batch of card receipts', function () {
    $other = overpaymentReceipt(Contact::factory()->customer()->create(), 100000);

    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [
            ['customer_receipt_id' => $other->id],
            ['customer_receipt_id' => $this->refund->id],
        ],
    ]);
    $entry = app(DepositPoster::class)->post($deposit)->load('lines');

    expect($deposit->fresh()->amount_cents)->toBe(75000)
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(75000);
});

it('reposts and voids a negative deposit', function () {
    $deposit = refundOnlyDeposit();
    app(DepositPoster::class)->post($deposit);

    // Add a bank fee to the same withdrawal and repost.
    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [
            ['customer_receipt_id' => $this->refund->id],
            ['account_id' => $this->expense->id, 'amount_cents' => -500],
        ],
    ], $deposit->fresh());
    $entry = app(DepositPoster::class)->repost($deposit)->load('lines');

    expect($deposit->fresh()->amount_cents)->toBe(-25500)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->credit_cents)->toBe(25500);

    app(DepositPoster::class)->void($deposit->fresh());

    expect($deposit->fresh()->status)->toBe(DepositStatus::Void)
        ->and($this->bank->fresh()->balance_cents)->toBe(0);
});

it('still refuses a deposit that nets to zero', function () {
    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [
            ['customer_receipt_id' => $this->payment->id],
            ['customer_receipt_id' => $this->refund->id],
        ],
    ]);

    expect(fn () => app(DepositPoster::class)->post($deposit))
        ->toThrow(PostingValidationException::class, 'A deposit needs at least one line and a total other than zero.');
});

it('posts a refund-only deposit from the deposit form and says it takes money out', function () {
    $component = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id);

    $index = collect($component->get('availableReceipts'))->search(fn ($r) => $r['receipt_id'] === $this->refund->id);

    $component->set("availableReceipts.$index.included", true)
        ->assertSeeHtml('data-test="deposit-negative-note"')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Deposit::query()->sole()->amount_cents)->toBe(-25000);
});

it('accepts signed deposit lines over the API but never a zero line', function () {
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $headers = ['Authorization' => "Bearer {$plain}"];

    $this->postJson('/api/v1/deposits', [
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 0]],
    ], $headers)->assertStatus(422)->assertJsonValidationErrors(['lines.0.amount_cents']);

    $this->postJson('/api/v1/deposits', [
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [
            ['customer_receipt_id' => $this->refund->id],
            ['account_id' => $this->expense->id, 'amount_cents' => -300, 'description' => 'Refund fee'],
        ],
    ], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.amount_cents', -25300);

    expect(CustomerReceipt::query()->find($this->refund->id)->journal_entry_id)->not->toBeNull();
});
