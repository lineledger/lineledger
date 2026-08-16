<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Printing\ChequePdfRenderer;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Acme Supplies', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->chequeMethod = PaymentMethod::query()->where('name', 'Cheque')->firstOrFail();
    $this->eftMethod = PaymentMethod::query()->where('name', 'EFT')->firstOrFail();

    $this->bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'B-200',
        'bill_date' => '2026-05-14',
        'due_date' => now()->addDays(30)->toDateString(),
        'memo' => 'Supplies order',
    ]);

    $this->bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 15463,
        'line_subtotal_cents' => 15463,
        'line_tax_cents' => 0,
        'line_total_cents' => 15463,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($this->bill);
    $this->bill->refresh();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postedChequePayment(): BillPayment
{
    $payment = BillPayment::create([
        'contact_id' => test()->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-CHQ-1',
        'payment_date' => '2026-05-14',
        'paid_from_account_id' => test()->bank->id,
        'payment_method_id' => test()->chequeMethod->id,
        'reference' => '1023',
        'memo' => 'Refund',
        'amount_cents' => 15463,
    ]);

    $payment->applications()->create(['bill_id' => test()->bill->id, 'amount_cents' => 15463]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    return $payment->fresh(['contact', 'paidFromAccount', 'applications.bill']);
}

it('requires a reference (cheque #) when the payment method is Cheque', function () {
    Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contactRole', 'vendor')
        ->set('contact_id', $this->vendor->id)
        ->set('paid_from_account_id', $this->bank->id)
        ->set('payment_method_id', $this->chequeMethod->id)
        ->set('reference', '')
        ->set('applyTable.0.apply', '154.63')
        ->call('save')
        ->assertHasErrors(['reference' => 'required']);
});

it('saves and redirects to the print-cheque URL when saveAndPrint is called', function () {
    Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contactRole', 'vendor')
        ->set('contact_id', $this->vendor->id)
        ->set('paid_from_account_id', $this->bank->id)
        ->set('payment_method_id', $this->chequeMethod->id)
        ->set('reference', '1023')
        ->set('applyTable.0.apply', '154.63')
        ->call('saveAndPrint')
        ->assertHasNoErrors()
        ->assertRedirect(route('bill-payments.print-cheque', [
            'company' => $this->company->slug,
            'payment' => BillPayment::query()->latest('id')->first()->id,
        ]));
});

it('serves a PDF from the print-cheque endpoint', function () {
    $payment = postedChequePayment();

    $response = $this->get(route('bill-payments.print-cheque', [
        'company' => $this->company->slug,
        'payment' => $payment->id,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

it('rejects print-cheque when method is not Cheque', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-EFT-1',
        'payment_date' => '2026-05-14',
        'paid_from_account_id' => $this->bank->id,
        'payment_method_id' => $this->eftMethod->id,
        'reference' => 'ABC',
        'amount_cents' => 15463,
    ]);

    $this->get(route('bill-payments.print-cheque', [
        'company' => $this->company->slug,
        'payment' => $payment->id,
    ]))->assertNotFound();
});

it('rejects print-cheque when no cheque # is set', function () {
    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-CHQ-NOREF',
        'payment_date' => '2026-05-14',
        'paid_from_account_id' => $this->bank->id,
        'payment_method_id' => $this->chequeMethod->id,
        'reference' => null,
        'amount_cents' => 15463,
    ]);

    $this->get(route('bill-payments.print-cheque', [
        'company' => $this->company->slug,
        'payment' => $payment->id,
    ]))->assertNotFound();
});

it('prepares cheque draw data in the QuickBooks format', function () {
    $payment = postedChequePayment();

    $data = app(ChequePdfRenderer::class)->dataFor($payment);

    expect($data['date_mmddyyyy'])->toBe('05142026');
    expect($data['date_slashed'])->toBe('5/14/2026');
    expect($data['payee'])->toBe('Acme Supplies');
    expect($data['amount_numeric'])->toBe('**154.63');
    expect($data['total_numeric'])->toBe('154.63');
    expect($data['memo'])->toBe('Refund');
    expect($data['amount_words'])->toEndWith('One Hundred Fifty-Four and 63/100');
    expect($data['amount_words'])->toStartWith('*'); // star-padded
    expect($data['lines'])->toHaveCount(1);
    expect($data['lines'][0])->toMatchArray([
        'account' => 'B-200',
        'description' => 'Supplies order',
        'amount' => '154.63',
    ]);
});

it('formats amounts in title-cased words for cheques', function () {
    expect(Money::fromCents(15463)->toWords())
        ->toBe('One Hundred Fifty-Four and 63/100');

    expect(Money::fromCents(123456)->toWords())
        ->toBe('One Thousand Two Hundred Thirty-Four and 56/100');

    expect(Money::fromCents(100)->toWords())
        ->toBe('One and 00/100');

    expect(Money::fromCents(0)->toWords())
        ->toBe('Zero and 00/100');
});
