<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Posting\TaxReturnPaymentPoster;
use App\Services\Tax\TaxReturnFiler;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->bankAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $this->expenseAccount = Account::query()->where('type', AccountType::Expense->value)->first();
    $this->incomeNonOpAccount = Account::query()->where('subtype', AccountSubtype::OtherIncome->value)->first()
        ?? Account::query()->where('type', AccountType::Income->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postInvoiceAndFileReturn(int $subtotalCents = 10000): TaxReturn
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-02-01',
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, test()->gst);

    $invoice->lines()->create([
        'account_id' => test()->incomeAccount->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    $return = TaxReturn::create([
        'tax_agency_id' => test()->gst->agency_id,
        'tax_return_no' => 'TR-'.uniqid(),
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'status' => TaxReturnStatus::Draft,
    ]);

    return app(TaxReturnFiler::class)->file($return);
}

it('posts an outgoing tax payment that clears the tax payable balance', function () {
    $return = postInvoiceAndFileReturn(10000);
    $payableAccount = $this->gst->agency->payableAccount;

    expect($payableAccount->fresh()->balance_cents)->toBe(500);
    expect($return->net_cents)->toBe(500);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-001',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 500,
    ]);

    app(TaxReturnPaymentPoster::class)->post($payment);

    $payment->refresh();

    expect($payment->status)->toBe(TaxReturnPaymentStatus::Posted);
    expect($payment->total_cents)->toBe(500);
    expect($payableAccount->fresh()->balance_cents)->toBe(0);
    expect($this->bankAccount->fresh()->balance_cents)->toBe(-500);
});

it('records penalty, interest and commission on an outgoing payment', function () {
    $return = postInvoiceAndFileReturn(10000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-PEN',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 500,
        'penalty_cents' => 100,
        'penalty_account_id' => $this->expenseAccount->id,
        'interest_cents' => 50,
        'interest_account_id' => $this->expenseAccount->id,
        'commission_cents' => 25,
        'commission_account_id' => $this->expenseAccount->id,
    ]);

    app(TaxReturnPaymentPoster::class)->post($payment);

    $payment->refresh();

    expect($payment->total_cents)->toBe(675);
    expect($this->bankAccount->fresh()->balance_cents)->toBe(-675);
    expect($this->expenseAccount->fresh()->balance_cents)->toBe(175);
});

it('rejects an outgoing payment with a penalty but no penalty account', function () {
    $return = postInvoiceAndFileReturn(10000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-NOACCT',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 500,
        'penalty_cents' => 100,
        'penalty_account_id' => null,
    ]);

    expect(fn () => app(TaxReturnPaymentPoster::class)->post($payment))
        ->toThrow(RuntimeException::class, 'Penalty account is required');
});

it('posts an incoming tax refund with interest received', function () {
    $vendor = Contact::create(['display_name' => 'Big Vendor', 'is_vendor' => true]);

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-REF',
        'bill_date' => '2026-02-01',
        'due_date' => '2026-02-01',
    ]);

    $totals = app(TaxCalculator::class)->line('1', 20000, $this->gst);
    $bill->lines()->create([
        'account_id' => Account::query()->where('type', AccountType::Expense->value)->first()->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'tax_code_id' => $this->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-REFUND',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'status' => TaxReturnStatus::Draft,
    ]);
    $return = app(TaxReturnFiler::class)->file($return);

    expect($return->net_cents)->toBe(-1000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-REF',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Incoming->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 1000,
        'interest_cents' => 50,
        'interest_account_id' => $this->incomeNonOpAccount->id,
    ]);

    app(TaxReturnPaymentPoster::class)->post($payment);

    $payment->refresh();
    expect($payment->total_cents)->toBe(1050);
    expect($this->bankAccount->fresh()->balance_cents)->toBe(1050);
    expect($this->gst->agency->payableAccount->fresh()->balance_cents)->toBe(0);
});

it('voids a posted payment and reverses the GL entry', function () {
    $return = postInvoiceAndFileReturn(10000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-VOID',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 500,
    ]);
    $poster = app(TaxReturnPaymentPoster::class);
    $poster->post($payment);

    expect($this->bankAccount->fresh()->balance_cents)->toBe(-500);

    $poster->void($payment->fresh());

    $payment->refresh();
    expect($payment->status)->toBe(TaxReturnPaymentStatus::Void);
    expect($this->bankAccount->fresh()->balance_cents)->toBe(0);
    expect($this->gst->agency->payableAccount->fresh()->balance_cents)->toBe(500);
});

it('rejects a zero-amount payment', function () {
    $return = postInvoiceAndFileReturn(10000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-ZERO',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 0,
    ]);

    expect(fn () => app(TaxReturnPaymentPoster::class)->post($payment))
        ->toThrow(RuntimeException::class, 'zero amount');
});

it('rejects penalty or commission on an incoming refund', function () {
    $return = postInvoiceAndFileReturn(10000);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-INVALID',
        'payment_date' => '2026-04-15',
        'direction' => TaxReturnPaymentDirection::Incoming->value,
        'bank_account_id' => $this->bankAccount->id,
        'net_amount_cents' => 1000,
        'penalty_cents' => 100,
        'penalty_account_id' => $this->expenseAccount->id,
    ]);

    expect(fn () => app(TaxReturnPaymentPoster::class)->post($payment))
        ->toThrow(RuntimeException::class, 'only valid on outgoing');
});
