<?php

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'V Co', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $this->bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'B-100',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Test',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($this->bill);
    $this->bill->refresh();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a full payment and marks the bill paid', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-001',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 20000,
    ]);

    $payment->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 20000]);

    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $this->bill->refresh();
    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();

    expect($this->bill->status)->toBe(BillStatus::Paid);
    expect($this->bill->amount_paid_cents)->toBe(20000);
    expect($this->bill->balanceCents())->toBe(0);

    expect($ap->fresh()->balance_cents)->toBe(0);
    // Bank balance went down 20000 (debit-normal account, credit of 20000 → negative balance)
    expect($this->bank->fresh()->balance_cents)->toBe(-20000);
    expect($this->vendor->fresh()->ap_balance_cents)->toBe(0);
});

it('funds a bill payment from a credit card (DR AP / CR card liability)', function () {
    // Regression guard: a credit-card pay-from was blocked only by a UI filter even
    // though the poster handles it — a card-funded payment increases the card liability.
    $card = $this->company->accounts()->create([
        'code' => 'CC-VISA',
        'name' => 'Visa',
        'subtype' => AccountSubtype::CreditCard->value,
        'type' => AccountSubtype::CreditCard->type()->value,
        'normal_balance' => AccountSubtype::CreditCard->type()->normalBalance()->value,
        'is_active' => true,
    ]);

    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-CC',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $card->id,
        'amount_cents' => 20000,
    ]);
    $payment->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 20000]);

    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $this->bill->refresh();
    expect($this->bill->status)->toBe(BillStatus::Paid)
        ->and($card->fresh()->balance_cents)->toBe(20000); // liability rose by the $200 charge
});

it('lists applied payments on the bill show page and excludes voided ones', function () {
    // A real, posted payment applied to the bill...
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-SHOWN',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 5000,
    ]);
    $payment->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 5000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    // ...and a voided payment that must NOT appear in the list.
    $voided = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-VOIDED',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 3000,
        'status' => BillPaymentStatus::Void,
    ]);
    $voided->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 3000]);

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill->fresh()])
        ->assertSeeHtml('data-test="bill-payment-applications"')
        ->assertSee('PAY-SHOWN')
        ->assertDontSee('PAY-VOIDED');
});

it('marks bill partial on a smaller payment', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-002',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 5000,
    ]);

    $payment->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 5000]);

    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $this->bill->refresh();
    expect($this->bill->status)->toBe(BillStatus::Partial);
    expect($this->bill->balanceCents())->toBe(15000);
});

it('voiding a payment reopens the bill and reverses the GL entry', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-003',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 20000,
    ]);

    $payment->applications()->create(['bill_id' => $this->bill->id, 'amount_cents' => 20000]);

    $poster = app(BillPaymentPoster::class);
    $poster->post($payment->fresh('applications'));
    $poster->void($payment->fresh());

    $this->bill->refresh();
    expect($this->bill->status)->toBe(BillStatus::Posted);
    expect($this->bill->amount_paid_cents)->toBe(0);
});
