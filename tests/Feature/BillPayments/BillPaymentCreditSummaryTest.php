<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Posting\VendorCreditPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postBillFor(Contact $vendor, Account $expense, string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Materials',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('shows the vendor credit and net balance when a vendor credit exists', function () {
    $vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    postBillFor($vendor, $this->expense, 'BILL-CR-1', 55500); // $555 bill

    $credit = VendorCredit::create([
        'contact_id' => $vendor->id,
        'vendor_credit_no' => 'VC-50',
        'vendor_credit_date' => now()->toDateString(),
    ]);
    $totals = app(TaxCalculator::class)->line('1', 5000);
    $credit->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'credit',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);
    app(VendorCreditPoster::class)->post($credit);

    $statementUrl = route('reports.contact-statement', ['company' => $this->company->slug, 'contact' => $vendor->id, 'kind' => 'ap']);

    $summary = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contact_id', $vendor->id)
        ->assertSeeHtml('data-test="payment-credit-summary"')
        ->assertSeeHtml('data-test="payment-credit-statement-link"')
        ->assertSeeHtml($statementUrl)
        ->get('creditSummary');

    expect($summary['credit'])->toBe(5000)
        ->and($summary['open_bills'])->toBe(55500)
        ->and($summary['net'])->toBe(50500);
});

it('shows no credit summary when the vendor has no available credit', function () {
    $vendor = Contact::create(['display_name' => 'No Credit Co', 'is_vendor' => true]);
    postBillFor($vendor, $this->expense, 'BILL-NC-1', 20000);

    $summary = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contact_id', $vendor->id)
        ->assertDontSeeHtml('data-test="payment-credit-summary"')
        ->get('creditSummary');

    expect($summary)->toBeNull();
});
