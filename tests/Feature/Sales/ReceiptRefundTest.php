<?php

use App\Actions\Banking\SaveCheque;
use App\Actions\Sales\RecordCustomerRefund;
use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A payment's unapplied credit, refunded from the payment's own page, and the
 * guards that keep a refunded payment from giving that credit away twice.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->where('is_system', true)->first();

    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Jones Family']);
    $this->payment = overpaymentReceipt($this->customer, 50000);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Refund $cents of the payment's credit by card, straight through the poster.
 * Created and posted in one transaction, as the API does, so a refused refund
 * leaves no draft behind.
 */
function cardRefundOf(CustomerReceipt $payment, int $cents, array $overrides = []): CustomerReceipt
{
    return DB::transaction(function () use ($payment, $cents, $overrides): CustomerReceipt {
        $refund = CustomerReceipt::create([
            'contact_id' => $payment->contact_id,
            'refunded_receipt_id' => $payment->id,
            'receipt_no' => 'REF-'.uniqid(),
            'receipt_date' => now()->toDateString(),
            'deposit_to_account_id' => test()->undeposited->id,
            'amount_cents' => -$cents,
            ...$overrides,
        ]);

        app(ReceiptPoster::class)->post($refund);

        return $refund->fresh();
    });
}

function showPayment(CustomerReceipt $payment)
{
    return Livewire::test('pages::receipts.show', ['company' => test()->company, 'receipt' => $payment->fresh()]);
}

it('shows a payment\'s unapplied credit and refunds it from the payment page', function () {
    showPayment($this->payment)
        ->assertSeeHtml('data-test="refund-receipt-button"')
        ->assertSeeHtml('data-test="receipt-unapplied-credit"')
        ->call('openRefund')
        ->assertSet('refundAmount', '500.00')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="receipt-refunds"')
        ->assertDontSeeHtml('data-test="refund-receipt-button"');

    $refund = CustomerReceipt::query()->where('refunded_receipt_id', $this->payment->id)->sole();

    expect($refund->amount_cents)->toBe(-50000)
        ->and($refund->from_invoice_id)->toBeNull()
        ->and($this->customer->fresh()->ar_balance_cents)->toBe(0);
});

it('refuses a refund from the payment page while someone is editing the payment', function () {
    $bookkeeper = editLockMember($this->company);
    app(EditLockManager::class)->acquire($this->payment, $bookkeeper);

    showPayment($this->payment)
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertDispatched('toast-show');

    expect($this->payment->fresh()->hasLiveRefunds())->toBeFalse();
});

it('names a refund receipt\'s source and offers no edit on it', function () {
    $invoice = overpaymentInvoice($this->customer, 1000);
    $refund = app(RecordCustomerRefund::class)->handle($this->payment, [
        'method' => 'card',
        'amount_cents' => 1000,
        'refund_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
    ], $invoice);

    showPayment($refund)
        ->assertSeeHtml('data-test="receipt-refund-of"')
        ->assertSee($this->payment->receipt_no)
        ->assertSee($invoice->invoice_no)
        ->assertDontSeeHtml('data-test="edit-receipt-button"')
        ->assertDontSeeHtml('data-test="refund-receipt-button"');

    $this->get(route('receipts.edit', ['company' => $this->company->slug, 'receipt' => $refund->id]))
        ->assertForbidden();

    expect(fn () => app(SaveReceipt::class)->handle([
        'contact_id' => $this->customer->id,
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => -500,
    ], $refund))->toThrow(PostingValidationException::class, 'A refund can\'t be edited.');
});

it('refuses to void a refunded payment, even for a draft refund cheque, until the refund is removed', function () {
    $refund = cardRefundOf($this->payment, 20000);

    expect(fn () => app(ReceiptPoster::class)->void($this->payment->fresh()))
        ->toThrow(PostingValidationException::class, 'This payment has been refunded.');

    app(ReceiptPoster::class)->void($refund);

    $cheque = app(RecordCustomerRefund::class)->handle($this->payment->fresh(), [
        'method' => 'cheque',
        'amount_cents' => 10000,
        'refund_date' => now()->toDateString(),
        'bank_account_id' => $this->bank->id,
    ]);

    expect($cheque->journal_entry_id)->toBeNull();
    expect(fn () => app(ReceiptPoster::class)->void($this->payment->fresh()))
        ->toThrow(PostingValidationException::class, 'This payment has been refunded.');

    $cheque->lines()->delete();
    $cheque->delete();

    app(ReceiptPoster::class)->void($this->payment->fresh());

    expect($this->payment->fresh()->status)->toBe(ReceiptStatus::Void);
});

it('keeps refunded credit out of the edit form and refuses applying it to an invoice', function () {
    cardRefundOf($this->payment, 40000);
    $openInvoice = overpaymentInvoice($this->customer, 50000);

    $form = Livewire::test('pages::receipts.form', ['company' => $this->company, 'receipt' => $this->payment->fresh()])
        ->assertSet('refundedCents', 40000)
        ->assertSeeHtml('data-test="receipt-refunded"')
        ->assertSeeHtml('data-test="receipt-customer-locked"');

    $row = collect($form->get('applyTable'))->search(fn ($r) => $r['invoice_id'] === $openInvoice->id);

    // Only the 100.00 still unapplied can go to the invoice.
    $form->set("applyTable.$row.apply", '500.00')
        ->call('save')
        ->assertHasErrors('applyTable');

    $form->set("applyTable.$row.apply", '100.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($openInvoice->fresh()->amount_paid_cents)->toBe(10000)
        ->and($this->payment->fresh()->unappliedCents())->toBe(0);
});

it('refuses lowering a payment below what it applied plus refunded, or moving it to another customer', function () {
    cardRefundOf($this->payment, 30000);

    $data = [
        'contact_id' => $this->customer->id,
        'receipt_date' => $this->payment->receipt_date->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 20000,
    ];

    expect(fn () => app(SaveReceipt::class)->handle($data, $this->payment->fresh()))
        ->toThrow(PostingValidationException::class, '300.00 of this payment has been refunded');

    $other = Contact::factory()->customer()->create();

    expect(fn () => app(SaveReceipt::class)->handle([...$data, 'contact_id' => $other->id, 'amount_cents' => 50000], $this->payment->fresh()))
        ->toThrow(PostingValidationException::class, 'can\'t move to another customer');

    // Lowering to exactly the refunded amount is fine.
    $saved = app(SaveReceipt::class)->handle([...$data, 'amount_cents' => 30000], $this->payment->fresh());
    app(ReceiptPoster::class)->repost($saved);

    expect($this->payment->fresh()->amount_cents)->toBe(30000)
        ->and($this->customer->fresh()->ar_balance_cents)->toBe(0);
});

it('guards refunds written straight through the posters', function () {
    // More than the payment's credit.
    expect(fn () => cardRefundOf($this->payment, 60000))
        ->toThrow(PostingValidationException::class, 'Refund cannot exceed the remaining 500.00.');

    // To a customer other than the one who paid.
    $other = Contact::factory()->customer()->create();
    expect(fn () => cardRefundOf($this->payment, 1000, ['contact_id' => $other->id]))
        ->toThrow(PostingValidationException::class, 'A refund must go to the customer who made the payment.');

    // A refund of a refund.
    $refund = cardRefundOf($this->payment, 1000);
    expect(fn () => cardRefundOf($refund, 500))
        ->toThrow(PostingValidationException::class, 'A refund can\'t itself be refunded.');

    // A draft refund cheque edited past the credit.
    $cheque = app(RecordCustomerRefund::class)->handle($this->payment->fresh(), [
        'method' => 'cheque',
        'amount_cents' => 4000,
        'refund_date' => now()->toDateString(),
        'bank_account_id' => $this->bank->id,
    ]);

    expect(fn () => app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => $cheque->cheque_no,
        'cheque_date' => now()->toDateString(),
        'payee_contact_id' => $this->customer->id,
        'lines' => [['account_id' => $this->ar->id, 'contact_id' => $this->customer->id, 'amount_cents' => 60000]],
    ], $cheque))->toThrow(PostingValidationException::class, 'Refund cannot exceed the remaining 490.00.');

    expect(Cheque::find($cheque->id)->amount_cents)->toBe(4000);
});

it('reports a payment that refunded more than it left unapplied', function () {
    $refund = cardRefundOf($this->payment, 50000);

    // Written around the guards: the payment's amount lowered in place.
    $this->payment->forceFill(['amount_cents' => 30000])->save();

    $rows = app(ReceiptPoster::class)->overRefundedReceiptRows($this->company->id);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->id)->toBe($this->payment->id)
        ->and((int) $rows->first()->refunded_cents)->toBe(50000);

    $this->artisan('integrity:check', ['company' => $this->company->id])
        ->expectsOutputToContain('more refunded than it left unapplied')
        ->assertFailed();

    expect($refund->exists)->toBeTrue();
});

it('shows the cheque form an error, not a crash, when a draft refund cheque is raised past the credit', function () {
    $cheque = app(RecordCustomerRefund::class)->handle($this->payment, [
        'method' => 'cheque',
        'amount_cents' => 50000,
        'refund_date' => now()->toDateString(),
        'bank_account_id' => $this->bank->id,
    ]);

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->set('lines.0.amount', '600.00')
        ->call('saveDraft')
        ->assertHasErrors('lines')
        ->assertNoRedirect();

    expect($cheque->fresh()->amount_cents)->toBe(50000);
});
