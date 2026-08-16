<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\VendorCreditStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Posting\VendorCreditPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postedVendorCredit(Contact $vendor, Account $expense, string $no, int $unitCents, string $qty = '1'): VendorCredit
{
    $credit = VendorCredit::create([
        'contact_id' => $vendor->id,
        'vendor_credit_no' => $no,
        'vendor_credit_date' => now()->toDateString(),
    ]);

    $totals = app(TaxCalculator::class)->line($qty, $unitCents);

    $credit->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Return to vendor',
        'quantity' => $qty,
        'unit_price_cents' => $unitCents,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(VendorCreditPoster::class)->post($credit);

    return $credit->fresh('journalEntry.lines');
}

it('posts a tax-free vendor credit: debits AP, credits expense', function () {
    $credit = postedVendorCredit($this->vendor, $this->expense, 'VC-001', 5000, '2');

    expect($credit->status)->toBe(VendorCreditStatus::Posted)
        ->and($credit->total_cents)->toBe(10000)
        ->and($credit->journal_entry_id)->not->toBeNull();

    $lines = $credit->journalEntry->lines;

    // AP control is debited by the total (reduces what we owe), tagged to the vendor.
    $apLine = $lines->firstWhere('account_id', $this->ap->id);
    expect((int) $apLine->debit_cents)->toBe(10000)
        ->and((int) $apLine->credit_cents)->toBe(0)
        ->and((int) $apLine->contact_id)->toBe($this->vendor->id);

    // Expense is credited (reverses the original purchase expense).
    $expenseLine = $lines->firstWhere('account_id', $this->expense->id);
    expect((int) $expenseLine->credit_cents)->toBe(10000)
        ->and((int) $expenseLine->debit_cents)->toBe(0);

    expect($credit->journalEntry->isBalanced())->toBeTrue();
});

it('credits recoverable tax back to the agency payable account', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $credit = VendorCredit::create([
        'contact_id' => $this->vendor->id,
        'vendor_credit_no' => 'VC-TAX',
        'vendor_credit_date' => now()->toDateString(),
    ]);
    $totals = app(TaxCalculator::class)->line('1', 10000, $gst);
    $credit->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Return',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(VendorCreditPoster::class)->post($credit);
    $credit->refresh()->load('journalEntry.lines');

    $payableAccountId = $gst->agency->payable_account_id;
    $taxLine = $credit->journalEntry->lines->firstWhere('account_id', $payableAccountId);

    expect((int) $taxLine->credit_cents)->toBe($totals['tax_cents'])
        ->and($credit->journalEntry->isBalanced())->toBeTrue();
});

it('nets a posted vendor credit against the vendor AP balance', function () {
    // A $300 bill, then a $100 vendor credit → net AP owed is $200.
    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-AP',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 30000,
        'line_subtotal_cents' => 30000,
        'line_tax_cents' => 0,
        'line_total_cents' => 30000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    postedVendorCredit($this->vendor, $this->expense, 'VC-NET', 10000);

    expect($this->vendor->fresh()->ap_balance_cents)->toBe(20000);
});

it('voids a vendor credit and restores the vendor AP balance', function () {
    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-V',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 30000,
        'line_subtotal_cents' => 30000,
        'line_tax_cents' => 0,
        'line_total_cents' => 30000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $credit = postedVendorCredit($this->vendor, $this->expense, 'VC-VOID', 10000);
    expect($this->vendor->fresh()->ap_balance_cents)->toBe(20000);

    app(VendorCreditPoster::class)->void($credit);

    expect($credit->fresh()->status)->toBe(VendorCreditStatus::Void)
        ->and($this->vendor->fresh()->ap_balance_cents)->toBe(30000);
});
