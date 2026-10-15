<?php

use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\CreditMemoStatus;
use App\Enums\ReceiptStatus;
use App\Enums\RefundMethod;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Services\Posting\CreditMemoPoster;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Lee Family']);
    $this->payment = overpaymentReceipt($this->customer, 30000);
    $this->refunds = app(RecordCustomerRefund::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** @return array<string, mixed> */
function cardRefundData(int $cents, array $overrides = []): array
{
    return [
        'method' => RefundMethod::Card,
        'amount_cents' => $cents,
        'refund_date' => now()->toDateString(),
        'deposit_to_account_id' => test()->undeposited->id,
        ...$overrides,
    ];
}

/** The field a refund's ValidationException is keyed to. */
function refundErrorField(Closure $refund): ?string
{
    try {
        $refund();
    } catch (ValidationException $e) {
        return array_key_first($e->errors());
    }

    return null;
}

it('keys its errors to the API field names', function () {
    expect(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(0))))->toBe('amount_cents')
        ->and(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(1000, ['refund_date' => 'not a date']))))->toBe('refund_date')
        ->and(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(1000, ['method' => 'wire']))))->toBe('method')
        ->and(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(1000, ['deposit_to_account_id' => $this->income->id]))))->toBe('deposit_to_account_id')
        ->and(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(1000, ['method' => 'cheque', 'bank_account_id' => null]))))->toBe('bank_account_id')
        ->and(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(30001))))->toBe('amount_cents');

    expect(CustomerReceipt::query()->whereNotNull('refunded_receipt_id')->exists())->toBeFalse()
        ->and(Cheque::query()->whereNotNull('refunded_receipt_id')->exists())->toBeFalse();
});

it('refuses a source that holds no refundable credit', function () {
    $draft = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-DRAFT',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
        'status' => ReceiptStatus::Draft,
    ]);

    expect(refundErrorField(fn () => $this->refunds->handle($draft, cardRefundData(1000))))->toBe('refunded_receipt_id');

    $refund = $this->refunds->handle($this->payment, cardRefundData(30000));

    // Fully refunded: nothing left for a second refund.
    expect(refundErrorField(fn () => $this->refunds->handle($this->payment->fresh(), cardRefundData(1))))->toBe('amount_cents')
        // A refund has no credit of its own.
        ->and(refundErrorField(fn () => $this->refunds->handle($refund, cardRefundData(1))))->toBe('refunded_receipt_id');
});

it('refuses an invoice of another customer as the refund\'s origin', function () {
    $stranger = Contact::factory()->customer()->create();
    $invoice = overpaymentInvoice($stranger, 1000);

    expect(refundErrorField(fn () => $this->refunds->handle($this->payment, cardRefundData(1000), $invoice)))->toBe('refunded_receipt_id');
});

it('posts a card refund and drafts a cheque refund, both linked to their source and invoice', function () {
    $invoice = overpaymentInvoice($this->customer, 1000);

    $card = $this->refunds->handle($this->payment, cardRefundData(10000, ['reference' => 'TERM-42']), $invoice);

    expect($card)->toBeInstanceOf(CustomerReceipt::class)
        ->and($card->status)->toBe(ReceiptStatus::Posted)
        ->and($card->amount_cents)->toBe(-10000)
        ->and($card->refunded_receipt_id)->toBe($this->payment->id)
        ->and($card->from_invoice_id)->toBe($invoice->id)
        ->and($card->reference)->toBe('TERM-42')
        ->and($card->memo)->toBe(__('Refund of overpayment on :no', ['no' => $this->payment->receipt_no]));

    $cheque = $this->refunds->handle($this->payment->fresh(), [
        'method' => 'cheque',
        'amount_cents' => 5000,
        'refund_date' => now()->toDateString(),
        'bank_account_id' => $this->bank->id,
    ], $invoice);

    expect($cheque)->toBeInstanceOf(Cheque::class)
        ->and($cheque->status)->toBe(ChequeStatus::Draft)
        ->and($cheque->refunded_receipt_id)->toBe($this->payment->id)
        ->and($cheque->from_invoice_id)->toBe($invoice->id)
        ->and($cheque->payee_contact_id)->toBe($this->customer->id)
        ->and($cheque->amount_cents)->toBe(5000);

    expect($this->payment->fresh()->refundedCents())->toBe(15000)
        ->and($this->payment->fresh()->remainingRefundableCents())->toBe(15000);
});

it('refunds a credit memo the same way the credit memo page always has', function () {
    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-ACT',
        'credit_memo_date' => now()->toDateString(),
    ]);
    $memo->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Return',
        'quantity' => '1',
        'unit_price_cents' => 8000,
        'line_subtotal_cents' => 8000,
        'line_tax_cents' => 0,
        'line_total_cents' => 8000,
        'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    $card = $this->refunds->handle($memo->fresh(), cardRefundData(3000));

    expect($card->credit_memo_id)->toBe($memo->id)
        ->and($card->refunded_receipt_id)->toBeNull()
        ->and($card->memo)->toBe(__('Refund of credit memo :no', ['no' => 'CM-ACT']));

    $cheque = $this->refunds->handle($memo->fresh(), ['method' => 'cheque', 'amount_cents' => 5000, 'refund_date' => now()->toDateString(), 'bank_account_id' => $this->bank->id]);

    expect($cheque->credit_memo_id)->toBe($memo->id)
        ->and($memo->fresh()->isFullyRefunded())->toBeTrue();

    $memo->forceFill(['status' => CreditMemoStatus::Draft])->save();
    expect(refundErrorField(fn () => $this->refunds->handle($memo->fresh(), cardRefundData(1))))->toBe('credit_memo_id');
});
