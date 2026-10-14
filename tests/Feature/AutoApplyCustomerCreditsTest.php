<?php

use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\ReceiptApplication;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['auto_apply_customer_credits' => true]);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Auto Apply Co',
        'is_customer' => true,
    ]);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeReceipt(int $cents, CarbonImmutable $date): CustomerReceipt
{
    $receipt = CustomerReceipt::create([
        'contact_id' => test()->customer->id,
        'receipt_no' => 'REC-'.uniqid(),
        'receipt_date' => $date,
        'deposit_to_account_id' => test()->undeposited->id,
        'amount_cents' => $cents,
    ]);
    app(ReceiptPoster::class)->post($receipt);

    return $receipt->fresh();
}

function makeCreditInvoice(int $cents, CarbonImmutable $date): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date->addDays(30),
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('auto-applies an unapplied receipt to a newly posted invoice when enabled', function () {
    $today = CarbonImmutable::create(2026, 5, 20);

    makeReceipt(500, $today->subDays(2));

    $invoice = makeCreditInvoice(50000, $today);

    expect((int) $invoice->amount_paid_cents)->toBe(500)
        ->and($invoice->status)->toBe(InvoiceStatus::Partial);

    $apps = ReceiptApplication::where('invoice_id', $invoice->id)->get();
    expect($apps)->toHaveCount(1)
        ->and((int) $apps->first()->amount_cents)->toBe(500);
});

it('consumes oldest receipt first when multiple unapplied credits exist', function () {
    $today = CarbonImmutable::create(2026, 5, 20);

    $older = makeReceipt(200, $today->subDays(10));
    $newer = makeReceipt(300, $today->subDays(2));

    $invoice = makeCreditInvoice(40000, $today);

    expect((int) $invoice->amount_paid_cents)->toBe(500);

    $appliedByReceipt = ReceiptApplication::where('invoice_id', $invoice->id)
        ->get()
        ->groupBy('customer_receipt_id')
        ->map(fn ($g) => (int) $g->sum('amount_cents'));

    expect($appliedByReceipt[$older->id])->toBe(200)
        ->and($appliedByReceipt[$newer->id])->toBe(300);
});

it('caps auto-apply at the invoice balance', function () {
    $today = CarbonImmutable::create(2026, 5, 20);

    makeReceipt(10000, $today->subDays(1));

    $invoice = makeCreditInvoice(3000, $today);

    expect((int) $invoice->amount_paid_cents)->toBe(3000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);

    expect((int) ReceiptApplication::where('invoice_id', $invoice->id)->sum('amount_cents'))->toBe(3000);
});

it('does not auto-apply when company setting is disabled', function () {
    $this->company->update(['auto_apply_customer_credits' => false]);

    $today = CarbonImmutable::create(2026, 5, 20);

    makeReceipt(500, $today->subDays(2));
    $invoice = makeCreditInvoice(50000, $today);

    expect((int) $invoice->amount_paid_cents)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted);

    expect(ReceiptApplication::where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('does not auto-apply credit that was refunded to the customer', function () {
    $today = CarbonImmutable::create(2026, 5, 20);

    $receipt = makeReceipt(30000, $today->subDays(5));

    // 200.00 of the 300.00 credit was paid back by card; a draft refund cheque
    // holds another 50.00.
    app(RecordCustomerRefund::class)->handle($receipt, [
        'method' => 'card',
        'amount_cents' => 20000,
        'refund_date' => $today->subDays(3)->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
    ]);
    app(RecordCustomerRefund::class)->handle($receipt->fresh(), [
        'method' => 'cheque',
        'amount_cents' => 5000,
        'refund_date' => $today->subDays(3)->toDateString(),
        'bank_account_id' => Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->value('id'),
    ]);

    $invoice = makeCreditInvoice(50000, $today);

    // Only the 50.00 neither applied nor refunded pays the new invoice; the
    // card refund receipt itself is never treated as credit.
    expect((int) $invoice->amount_paid_cents)->toBe(5000)
        ->and(ReceiptApplication::where('invoice_id', $invoice->id)->pluck('customer_receipt_id')->all())->toBe([$receipt->id])
        ->and($receipt->fresh()->unappliedCents())->toBe(0);
});
