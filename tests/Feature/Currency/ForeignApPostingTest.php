<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->usdCurrency = $this->company->currencies()->where('currency_code', 'USD')->first();

    $this->vendor = Contact::create([
        'display_name' => 'US Vendor',
        'is_vendor' => true,
        'currency_code' => 'USD',
    ]);

    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postUsdBill(int $foreignTotal, string $rate, string $no = 'BILL-USD'): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency_code' => 'USD',
        'fx_rate' => $rate,
    ]);

    $bill->lines()->create([
        'account_id' => test()->expenseAccount->id,
        'description' => 'Imported service',
        'quantity' => '1',
        'unit_price_cents' => $foreignTotal,
        'line_subtotal_cents' => $foreignTotal,
        'line_tax_cents' => 0,
        'line_total_cents' => $foreignTotal,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->refresh();
}

it('posts a foreign bill crediting the foreign AP control', function () {
    $bill = postUsdBill(100_000, '1.30'); // 1,000 USD @ 1.30

    $entry = $bill->journalEntry;
    expect($entry->isBalanced())->toBeTrue()
        ->and($bill->home_total_cents)->toBe(130_000);

    $apControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ap_account_id);
    expect($apControl->fresh()->balance_cents)->toBe(130_000)
        ->and($apControl->fresh()->foreignBalanceCents())->toBe(100_000);

    expect($this->expenseAccount->fresh()->balance_cents)->toBe(130_000);
});

it('realizes an exchange loss when paying at a higher rate than the bill', function () {
    $bill = postUsdBill(100_000, '1.30');

    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-USD',
        'payment_date' => '2026-04-01',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.35',
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 100_000]);

    app(BillPaymentPoster::class)->post($payment);

    $entry = $payment->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue();

    // Bank paid out the home value at the payment rate.
    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    expect($bankLine->credit_cents)->toBe(135_000);

    // Foreign AP control fully cleared.
    $apControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ap_account_id);
    expect($apControl->fresh()->balance_cents)->toBe(0)
        ->and($apControl->fresh()->foreignBalanceCents())->toBe(0);

    // Paid 1,350 to settle a 1,300 liability → 50.00 CAD realized loss (debit).
    $fxLine = $entry->lines->firstWhere('account_id', $this->company->exchange_gain_loss_account_id);
    expect($fxLine->debit_cents)->toBe(5_000)
        ->and($fxLine->credit_cents)->toBe(0);
});

it('realizes an exchange gain when paying at a lower rate than the bill', function () {
    $bill = postUsdBill(100_000, '1.35');

    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-USD2',
        'payment_date' => '2026-04-01',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.30',
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 100_000]);

    app(BillPaymentPoster::class)->post($payment);

    $entry = $payment->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue();

    // Paid 1,300 to settle a 1,350 liability → 50.00 CAD realized gain (credit).
    $fxLine = $entry->lines->firstWhere('account_id', $this->company->exchange_gain_loss_account_id);
    expect($fxLine->credit_cents)->toBe(5_000)
        ->and($fxLine->debit_cents)->toBe(0);
});
