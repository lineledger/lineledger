<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Services\Posting\BillPoster;
use App\Services\Posting\TaxCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Office Supplies Co', 'is_vendor' => true]);
    $this->employee = Contact::create(['display_name' => 'Alex Employee', 'is_employee' => true]);
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a vendor bill with recoverable tax: DR expense + DR tax payable / CR AP', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-001',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $totals = app(TaxCalculator::class)->line('1', 10000, $gst);

    $bill->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Office supplies',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);
    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Posted);
    expect($bill->total_cents)->toBe(10500);

    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();
    $gstPayable = $gst->agency->payableAccount;

    // AP balance is a credit normal account; posted as a credit of 10500
    expect($ap->fresh()->balance_cents)->toBe(10500);
    // Expense increases on debit side
    expect($this->expenseAccount->fresh()->balance_cents)->toBe(10000);
    // GST payable is a credit normal account; ITC debits it, reducing it
    expect($gstPayable->fresh()->balance_cents)->toBe(-500);

    expect($this->vendor->fresh()->ap_balance_cents)->toBe(10500);
});

it('treats non-recoverable tax as gross-up to the expense (no ITC)', function () {
    $ex = TaxCode::where('code', 'EX')->firstOrFail();

    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-002',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    // Manually craft line with non-recoverable tax > 0 (simulate PST)
    $bill->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'PST-only',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $ex->id,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 700, // simulate a 7% non-recoverable tax
        'line_total_cents' => 10700,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);
    $bill->refresh();

    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();

    // Total billed to AP includes the tax
    expect($ap->fresh()->balance_cents)->toBe(10700);
    // Entire amount including non-recoverable tax goes to expense
    expect($this->expenseAccount->fresh()->balance_cents)->toBe(10700);
});

it('posts an employee reimbursement to the Employee Reimbursements Payable account', function () {
    $bill = Bill::create([
        'contact_id' => $this->employee->id,
        'bill_type' => BillType::Reimbursement,
        'bill_no' => 'REIM-001',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $bill->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Travel — taxi',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);
    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Posted);

    $reimbursementsPayable = Account::query()
        ->where('subtype', AccountSubtype::CurrentLiability->value)
        ->where('name', 'Employee Reimbursements Payable')
        ->where('is_system', true)
        ->first();

    expect($reimbursementsPayable)->not->toBeNull();
    expect($reimbursementsPayable->fresh()->balance_cents)->toBe(5000);
    expect($this->expenseAccount->fresh()->balance_cents)->toBe(5000);

    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();
    expect($ap->fresh()->balance_cents)->toBe(0); // AP must not be touched by a reimbursement
});

it('voids a posted bill and reverses the GL entry', function () {
    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-003',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $bill->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Test',
        'quantity' => '1',
        'unit_price_cents' => 3000,
        'line_subtotal_cents' => 3000,
        'line_tax_cents' => 0,
        'line_total_cents' => 3000,
        'line_order' => 0,
    ]);

    $poster = app(BillPoster::class);
    $poster->post($bill);
    $poster->void($bill->fresh());

    $bill->refresh();
    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();

    expect($bill->status)->toBe(BillStatus::Void);
    expect($ap->fresh()->balance_cents)->toBe(0);
    expect($this->expenseAccount->fresh()->balance_cents)->toBe(0);
});
