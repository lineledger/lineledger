<?php

use App\Actions\Banking\SaveDeposit;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\DepositPoster;
use Livewire\Livewire;

/*
 * The case that prompted the feature: a family paid an invoice by credit card,
 * then paid it again by cheque, and the card payment was refunded. No services
 * are credited, so there's no credit memo — the cheque is an overpayment
 * (unapplied customer credit) and the refund pays it back from the invoice.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->where('is_system', true)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $this->family = Contact::factory()->customer()->create(['display_name' => 'Smith Family']);
    $this->invoice = overpaymentInvoice($this->family, 100000);
    $this->card = overpaymentReceipt($this->family, 100000, $this->invoice, now()->subDays(6)->toDateString(), 'Credit card');
    $this->cheque = overpaymentReceipt($this->family, 100000, null, now()->subDays(4)->toDateString(), 'Cheque');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function showOverpaidInvoice()
{
    return Livewire::test('pages::invoices.show', ['company' => test()->company, 'invoice' => test()->invoice->fresh()]);
}

it('lists the payment and the overpayment, and offers a refund', function () {
    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->family->fresh()->ar_balance_cents)->toBe(-100000);

    showOverpaidInvoice()
        ->assertSeeHtml('data-test="invoice-payment-row"')
        ->assertSeeHtml('data-test="invoice-overpayment-row"')
        ->assertSeeHtml('data-test="refund-invoice-button"')
        ->assertSeeHtml('data-test="refund-invoice-menu-item"')
        ->assertSee($this->card->receipt_no)
        ->assertSee($this->cheque->receipt_no)
        ->assertSee(__('Credit remaining: :amount', ['amount' => '1,000.00']));
});

it('offers no refund when the customer has no overpayment', function () {
    $other = Contact::factory()->customer()->create();
    $invoice = overpaymentInvoice($other, 5000);
    overpaymentReceipt($other, 5000, $invoice);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice->fresh()])
        ->assertSeeHtml('data-test="invoice-payment-row"')
        ->assertDontSeeHtml('data-test="invoice-overpayments"')
        ->assertDontSeeHtml('data-test="refund-invoice-button"')
        ->assertDontSeeHtml('data-test="refund-invoice-menu-item"');
});

it('refunds the overpayment by card as a negative receipt, leaving the invoice paid by its card payment', function () {
    showOverpaidInvoice()
        ->call('openRefund')
        ->assertSet('refundSourceId', $this->cheque->id)
        ->assertSet('refundAmount', '1000.00')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSee(__('Credit remaining: :amount', ['amount' => '0.00']))
        ->assertSeeHtml('data-test="delete-refund-receipt"')
        ->assertDontSeeHtml('data-test="refund-invoice-button"');

    $refund = CustomerReceipt::query()->where('refunded_receipt_id', $this->cheque->id)->sole();

    expect($refund->amount_cents)->toBe(-100000)
        ->and($refund->from_invoice_id)->toBe($this->invoice->id)
        ->and($refund->contact_id)->toBe($this->family->id)
        ->and($refund->status)->toBe(ReceiptStatus::Posted)
        ->and($refund->deposit_to_account_id)->toBe($this->undeposited->id);

    // DR Accounts Receivable for the family, CR Undeposited Funds.
    $arLine = JournalLine::query()->where('journal_entry_id', $refund->journal_entry_id)->where('account_id', $this->ar->id)->sole();
    expect($arLine->debit_cents)->toBe(100000)->and($arLine->contact_id)->toBe($this->family->id);
    $undepLine = JournalLine::query()->where('journal_entry_id', $refund->journal_entry_id)->where('account_id', $this->undeposited->id)->sole();
    expect($undepLine->credit_cents)->toBe(100000);

    // The invoice keeps its card payment and stays paid; nothing was rewritten.
    $invoice = $this->invoice->fresh('receiptApplications');
    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid_cents)->toBe(100000)
        ->and($invoice->receiptApplications->pluck('customer_receipt_id')->all())->toBe([$this->card->id]);

    expect($this->family->fresh()->ar_balance_cents)->toBe(0)
        ->and($this->cheque->fresh()->unappliedCents())->toBe(0)
        ->and($this->cheque->fresh()->remainingRefundableCents())->toBe(0);
});

it('refunds the overpayment by cheque as a draft that clears the credit once posted', function () {
    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'cheque')
        ->call('submitRefund')
        ->assertHasNoErrors()
        ->assertRedirect();

    $cheque = Cheque::query()->where('refunded_receipt_id', $this->cheque->id)->sole();

    expect($cheque->journal_entry_id)->toBeNull()
        ->and($cheque->from_invoice_id)->toBe($this->invoice->id)
        ->and($cheque->payee_contact_id)->toBe($this->family->id)
        ->and($cheque->amount_cents)->toBe(100000)
        ->and($cheque->lines)->toHaveCount(1)
        ->and($cheque->lines->first()->account_id)->toBe($this->ar->id)
        ->and($cheque->lines->first()->contact_id)->toBe($this->family->id);

    // A draft already holds the credit, so it can't be refunded twice.
    expect($this->cheque->fresh()->remainingRefundableCents())->toBe(0);

    app(ChequePoster::class)->post($cheque);

    expect($this->family->fresh()->ar_balance_cents)->toBe(0)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('supports a partial refund and refuses an over-refund or a zero refund', function () {
    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundAmount', '400.00')
        ->call('submitRefund')
        ->assertHasNoErrors();

    expect($this->cheque->fresh()->remainingRefundableCents())->toBe(60000);

    showOverpaidInvoice()
        ->call('openRefund')
        ->assertSet('refundAmount', '600.00')
        ->set('refundMethod', 'card')
        ->set('refundAmount', '700.00')
        ->call('submitRefund')
        ->assertHasErrors('refundAmount');

    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundAmount', '0')
        ->call('submitRefund')
        ->assertHasErrors('refundAmount');

    expect(CustomerReceipt::query()->where('refunded_receipt_id', $this->cheque->id)->count())->toBe(1);
});

it('lets the user pick which overpayment to refund, and never one it was not offered', function () {
    $later = overpaymentReceipt($this->family, 2500, null, now()->subDays(2)->toDateString());

    $stranger = Contact::factory()->customer()->create();
    $strangersCredit = overpaymentReceipt($stranger, 9000);

    // The newest overpayment is offered first.
    $component = showOverpaidInvoice()
        ->call('openRefund')
        ->assertSet('refundSourceId', $later->id)
        ->assertSet('refundAmount', '25.00')
        ->assertSeeHtml('data-test="refund-source-select"')
        ->set('refundSourceId', $this->cheque->id)
        ->assertSet('refundAmount', '1000.00');

    // A payment the page never offered falls back to one it did.
    $component->set('refundSourceId', $strangersCredit->id)
        ->set('refundMethod', 'card')
        ->set('refundAmount', '10.00')
        ->call('submitRefund')
        ->assertHasNoErrors();

    expect($strangersCredit->fresh()->hasLiveRefunds())->toBeFalse()
        ->and($later->fresh()->refundedCents())->toBe(1000);
});

it('restores the credit when a card refund is removed', function () {
    showOverpaidInvoice()->call('openRefund')->set('refundMethod', 'card')->call('submitRefund');

    $refund = CustomerReceipt::query()->where('refunded_receipt_id', $this->cheque->id)->sole();

    showOverpaidInvoice()
        ->call('deleteRefundReceipt', $refund->id)
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="refund-invoice-button"');

    expect($refund->fresh()->status)->toBe(ReceiptStatus::Void)
        ->and($this->cheque->fresh()->remainingRefundableCents())->toBe(100000)
        ->and($this->family->fresh()->ar_balance_cents)->toBe(-100000);
});

it('deletes a draft refund cheque and restores the credit', function () {
    showOverpaidInvoice()->call('openRefund')->set('refundMethod', 'cheque')->call('submitRefund');

    $cheque = Cheque::query()->where('refunded_receipt_id', $this->cheque->id)->sole();

    showOverpaidInvoice()->call('deleteRefundCheque', $cheque->id)->assertHasNoErrors();

    expect(Cheque::find($cheque->id))->toBeNull()
        ->and($this->cheque->fresh()->remainingRefundableCents())->toBe(100000);
});

it('refuses to remove a card refund that is already on a deposit', function () {
    showOverpaidInvoice()->call('openRefund')->set('refundMethod', 'card')->call('submitRefund');

    $refund = CustomerReceipt::query()->where('refunded_receipt_id', $this->cheque->id)->sole();

    // The day's batch: both payments in, the card refund out.
    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => now()->toDateString(),
        'lines' => [
            ['customer_receipt_id' => $this->card->id],
            ['customer_receipt_id' => $this->cheque->id],
            ['customer_receipt_id' => $refund->id],
        ],
    ]);
    app(DepositPoster::class)->post($deposit);

    showOverpaidInvoice()
        ->call('deleteRefundReceipt', $refund->id)
        ->assertDispatched('toast-show');

    expect($refund->fresh()->status)->toBe(ReceiptStatus::Posted);
});

it('checks the payment\'s edit lock for a refund, not the invoice\'s', function () {
    $bookkeeper = editLockMember($this->company);
    $locks = app(EditLockManager::class);

    // Someone editing the payment whose credit would be refunded blocks it.
    $lease = $locks->acquire($this->cheque, $bookkeeper);

    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertDispatched('toast-show');

    expect($this->cheque->fresh()->hasLiveRefunds())->toBeFalse();

    $locks->release($lease->token, $bookkeeper);

    // Someone editing the invoice doesn't: the refund leaves the invoice alone,
    // and their editing session isn't disturbed.
    $locks->acquire($this->invoice, $bookkeeper);
    $versionBefore = $locks->currentVersion($this->invoice);

    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->call('submitRefund')
        ->assertHasNoErrors();

    expect($this->cheque->fresh()->hasLiveRefunds())->toBeTrue()
        ->and($locks->currentVersion($this->invoice))->toBe($versionBefore);
});

it('does not offer a foreign-currency payment as an overpayment', function () {
    CustomerReceipt::create([
        'contact_id' => $this->family->id,
        'receipt_no' => 'REC-USD',
        'receipt_date' => now()->subDays(3)->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
        'currency_code' => 'USD',
        'status' => ReceiptStatus::Posted,
    ]);

    showOverpaidInvoice()
        ->assertDontSee('REC-USD')
        ->call('openRefund')
        ->assertSet('refundSourceId', $this->cheque->id);
});

it('refuses a refund dated before the payment it refunds', function () {
    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundDate', now()->subDays(30)->toDateString())
        ->call('submitRefund')
        ->assertHasErrors('refundDate');

    expect($this->cheque->fresh()->hasLiveRefunds())->toBeFalse();
});

it('writes nothing when the refund falls in a closed period', function () {
    $this->company->forceFill(['lock_date' => now()->subDay()->toDateString()])->save();

    showOverpaidInvoice()
        ->call('openRefund')
        ->set('refundMethod', 'card')
        ->set('refundDate', now()->subDays(2)->toDateString())
        ->call('submitRefund')
        ->assertDispatched('toast-show');

    expect(CustomerReceipt::query()->where('refunded_receipt_id', $this->cheque->id)->exists())->toBeFalse()
        ->and($this->family->fresh()->ar_balance_cents)->toBe(-100000);
});
