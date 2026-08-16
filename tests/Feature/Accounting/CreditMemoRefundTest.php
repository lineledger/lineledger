<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Acme Corp']);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** Create and post a tax-free credit memo of the given amount. */
function postedCreditMemo(int $amountCents): CreditMemo
{
    $memo = CreditMemo::create([
        'contact_id' => test()->customer->id,
        'credit_memo_no' => 'CM-'.fake()->unique()->numberBetween(1000, 9999),
        'credit_memo_date' => now()->toDateString(),
    ]);

    $memo->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Return',
        'quantity' => '1',
        'unit_price_cents' => $amountCents,
        'line_subtotal_cents' => $amountCents,
        'line_tax_cents' => 0,
        'line_total_cents' => $amountCents,
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);

    return $memo->fresh();
}

it('hides the refund button until the credit memo is posted', function () {
    $draft = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-DRAFT',
        'credit_memo_date' => now()->toDateString(),
        'status' => CreditMemoStatus::Draft,
    ]);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $draft])
        ->assertDontSeeHtml('data-test="refund-credit-memo-button"');

    $posted = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $posted])
        ->assertSeeHtml('data-test="refund-credit-memo-button"');
});

it('creates a draft cheque coded to AR when refunding by cheque', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'cheque')
        ->call('submitRefund')
        ->assertHasNoErrors()
        ->assertRedirect();

    $cheque = Cheque::where('credit_memo_id', $memo->id)->firstOrFail();

    expect($cheque->journal_entry_id)->toBeNull();
    expect($cheque->payee_contact_id)->toBe($this->customer->id);
    expect($cheque->amount_cents)->toBe(10000);
    expect($cheque->lines)->toHaveCount(1);
    expect($cheque->lines->first()->account_id)->toBe($this->ar->id);
});

it('clears the customer credit when the refund cheque is posted', function () {
    $memo = postedCreditMemo(10000);

    expect($this->customer->fresh()->ar_balance_cents)->toBe(-10000);
    expect($this->ar->fresh()->balance_cents)->toBe(-10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'cheque')
        ->call('submitRefund');

    $cheque = Cheque::where('credit_memo_id', $memo->id)->firstOrFail();
    app(ChequePoster::class)->post($cheque);

    // AR control nets to zero; bank paid out.
    expect($this->ar->fresh()->balance_cents)->toBe(0);
    expect($this->bank->fresh()->balance_cents)->toBe(-10000);

    // The AR debit carries the customer for the GL-driven statement/aging.
    $arLine = JournalLine::where('journal_entry_id', $cheque->fresh()->journal_entry_id)
        ->where('account_id', $this->ar->id)
        ->firstOrFail();
    expect($arLine->debit_cents)->toBe(10000);
    expect($arLine->contact_id)->toBe($this->customer->id);

    // Cached AR balance agrees, and the memo reads as fully refunded.
    expect($this->customer->fresh()->ar_balance_cents)->toBe(0);
    expect($memo->fresh()->isFullyRefunded())->toBeTrue();
});

it('records a credit-card refund as a negative receipt to Undeposited Funds', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $receipt = CustomerReceipt::where('credit_memo_id', $memo->id)->firstOrFail();

    expect($receipt->amount_cents)->toBe(-10000);
    expect($receipt->deposit_to_account_id)->toBe($this->undeposited->id);
    expect($receipt->journal_entry_id)->not->toBeNull();
    expect($receipt->applications)->toHaveCount(0);

    // GL: DR Accounts Receivable (carrying the customer), CR Undeposited Funds.
    $arLine = JournalLine::where('journal_entry_id', $receipt->journal_entry_id)
        ->where('account_id', $this->ar->id)
        ->firstOrFail();
    expect($arLine->debit_cents)->toBe(10000);
    expect($arLine->credit_cents)->toBe(0);
    expect($arLine->contact_id)->toBe($this->customer->id);

    $undepLine = JournalLine::where('journal_entry_id', $receipt->journal_entry_id)
        ->where('account_id', $this->undeposited->id)
        ->firstOrFail();
    expect($undepLine->credit_cents)->toBe(10000);

    expect($this->ar->fresh()->balance_cents)->toBe(0);
    expect($this->customer->fresh()->ar_balance_cents)->toBe(0);
    expect($memo->fresh()->isFullyRefunded())->toBeTrue();
});

it('offers the card refund in the next deposit batch', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund');

    $available = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->get('availableReceipts');

    $amounts = collect($available)->pluck('amount');
    expect($amounts)->toContain(-10000);
});

it('supports partial refunds and blocks over-refunding', function () {
    $memo = postedCreditMemo(10000);

    // Refund $30 by card.
    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundAmount', '30.00')
        ->call('submitRefund')
        ->assertHasNoErrors();

    expect($memo->fresh()->refundedCents())->toBe(3000);
    expect($memo->fresh()->remainingRefundableCents())->toBe(7000);
    expect($memo->fresh()->isFullyRefunded())->toBeFalse();

    // Attempting to refund more than the $70 remaining is rejected.
    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo->fresh()])
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundAmount', '90.00')
        ->call('submitRefund')
        ->assertHasErrors('refundAmount');

    expect(CustomerReceipt::where('credit_memo_id', $memo->id)->count())->toBe(1);
});

it('allows a negative refund receipt but still rejects negatives on ordinary receipts', function () {
    $memo = postedCreditMemo(10000);

    $refund = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'credit_memo_id' => $memo->id,
        'receipt_no' => 'REF-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => -5000,
    ]);

    expect(fn () => app(ReceiptPoster::class)->post($refund))->not->toThrow(Exception::class);

    $ordinary = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-NEG',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => -5000,
    ]);

    expect(fn () => app(ReceiptPoster::class)->post($ordinary))
        ->toThrow(RuntimeException::class, 'Receipt amount must be positive.');
});

it('hard-deletes a draft refund cheque and restores the refundable balance', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'cheque')
        ->call('submitRefund');

    $cheque = Cheque::where('credit_memo_id', $memo->id)->firstOrFail();
    expect($cheque->journal_entry_id)->toBeNull();
    expect($memo->fresh()->remainingRefundableCents())->toBe(0);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo->fresh()])
        ->call('deleteRefundCheque', $cheque->id)
        ->assertHasNoErrors();

    expect(Cheque::find($cheque->id))->toBeNull();
    expect($cheque->lines()->count())->toBe(0);
    expect($memo->fresh()->remainingRefundableCents())->toBe(10000);
});

it('voids a posted refund cheque with a reversing entry and restores the customer credit', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'cheque')
        ->call('submitRefund');

    $cheque = Cheque::where('credit_memo_id', $memo->id)->firstOrFail();
    app(ChequePoster::class)->post($cheque);

    // Refund posted: AR control nets to zero, bank paid out.
    expect($this->ar->fresh()->balance_cents)->toBe(0);
    expect($this->customer->fresh()->ar_balance_cents)->toBe(0);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo->fresh()])
        ->call('deleteRefundCheque', $cheque->id)
        ->assertHasNoErrors();

    $cheque->refresh();
    expect($cheque->status->value)->toBe('void');

    // The credit is restored: customer is owed the credit again and the memo is refundable.
    expect($this->ar->fresh()->balance_cents)->toBe(-10000);
    expect($this->customer->fresh()->ar_balance_cents)->toBe(-10000);
    expect($memo->fresh()->remainingRefundableCents())->toBe(10000);
});

it('voids a card refund receipt with a reversing entry and restores the customer credit', function () {
    $memo = postedCreditMemo(10000);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund');

    $receipt = CustomerReceipt::where('credit_memo_id', $memo->id)->firstOrFail();
    expect($this->customer->fresh()->ar_balance_cents)->toBe(0);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo->fresh()])
        ->call('deleteRefundReceipt', $receipt->id)
        ->assertHasNoErrors();

    $receipt->refresh();
    expect($receipt->status->value)->toBe('void');
    expect($this->ar->fresh()->balance_cents)->toBe(-10000);
    expect($this->customer->fresh()->ar_balance_cents)->toBe(-10000);
    expect($memo->fresh()->remainingRefundableCents())->toBe(10000);
});

it('rejects a positive refund receipt and a zero receipt', function () {
    $memo = postedCreditMemo(10000);

    $positiveRefund = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'credit_memo_id' => $memo->id,
        'receipt_no' => 'REF-POS',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
    ]);

    expect(fn () => app(ReceiptPoster::class)->post($positiveRefund))
        ->toThrow(RuntimeException::class, 'Refund receipt amount must be negative.');

    $zero = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-ZERO',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 0,
    ]);

    expect(fn () => app(ReceiptPoster::class)->post($zero))
        ->toThrow(RuntimeException::class, 'Receipt amount cannot be zero.');
});
