<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makePostedBill(object $test, BillType $type = BillType::Vendor): Bill
{
    $vendor = Contact::create([
        'display_name' => 'Acme Supplies Ltd',
        'is_vendor' => true,
        'billing_line1' => '99 Industrial Way',
        'billing_city' => 'Victoria',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V8V 2B2',
    ]);

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => $type,
        'bill_no' => $type === BillType::Reimbursement ? 'REIMB-1' : 'BILL-PRINT-1',
        'bill_date' => CarbonImmutable::create(2026, 5, 24),
        'due_date' => CarbonImmutable::create(2026, 6, 23),
    ]);

    $bill->lines()->create([
        'account_id' => $test->expense->id,
        'description' => 'Casket hardware',
        'quantity' => '2',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('returns the bill as an inline PDF', function () {
    $bill = makePostedBill($this);

    $response = $this->get(route('bills.print', [
        'company' => $this->company->slug,
        'bill' => $bill->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('404s when the bill belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = makePostedBill((object) [
        'expense' => Account::query()->where('company_id', $otherCompany->id)->where('subtype', AccountSubtype::Expense->value)->first(),
    ]);
    app()->instance('current_company', $this->company);

    $this->get(route('bills.print', [
        'company' => $this->company->slug,
        'bill' => $foreign->id,
    ]))->assertNotFound();
});

it('renders the bill without GL account codes', function () {
    $bill = makePostedBill($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $html = view('pdf.bills.bill', [
        'company' => $this->company,
        'bill' => $bill,
        'isReimbursement' => false,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'taxSummary' => [],
        'logoData' => null,
    ])->render();

    expect($html)
        ->toContain('BILL')
        ->toContain('BILL-PRINT-1')
        ->toContain('Acme Supplies Ltd')
        ->toContain('Vendor')
        ->not->toContain($this->expense->code)
        ->not->toContain($this->expense->name);
});

it('renders a reimbursement with its own title', function () {
    $bill = makePostedBill($this, BillType::Reimbursement);

    $response = $this->get(route('reimbursements.print', [
        'company' => $this->company->slug,
        'bill' => $bill->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');

    $html = view('pdf.bills.bill', [
        'company' => $this->company,
        'bill' => $bill->load('lines.taxCode', 'lines.item', 'contact', 'terms'),
        'isReimbursement' => true,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'taxSummary' => [],
        'logoData' => null,
    ])->render();

    expect($html)->toContain('REIMBURSEMENT')->toContain('Pay To');
});

it('returns the bill payment as an inline PDF', function () {
    $bill = makePostedBill($this);

    $payment = BillPayment::create([
        'contact_id' => $bill->contact_id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-PRINT-1',
        'payment_date' => CarbonImmutable::create(2026, 5, 24),
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 10000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 10000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $response = $this->get(route('bill-payments.print', [
        'company' => $this->company->slug,
        'payment' => $payment->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');

    $html = view('pdf.bill-payments.payment', [
        'company' => $this->company,
        'payment' => $payment->fresh()->load('contact', 'paymentMethod', 'applications.bill'),
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'logoData' => null,
    ])->render();

    expect($html)
        ->toContain('PAYMENT')
        ->toContain('PAY-PRINT-1')
        ->toContain('Acme Supplies Ltd')
        ->toContain('BILL-PRINT-1')
        ->toContain('Amount Paid');
});
