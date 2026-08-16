<?php

use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a tax-free credit memo and decreases AR', function () {
    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-001',
        'credit_memo_date' => now()->toDateString(),
    ]);

    $calc = app(TaxCalculator::class);
    $totals = $calc->line('2', 5000);

    $memo->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Return',
        'quantity' => '2',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);

    $memo->refresh();

    expect($memo->status)->toBe(CreditMemoStatus::Posted);
    expect($memo->total_cents)->toBe(10000);
    expect($memo->journal_entry_id)->not->toBeNull();

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    expect($ar->fresh()->balance_cents)->toBe(-10000);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(-10000);
});

it('posts a credit memo with GST tax to per-agency payable account', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-002',
        'credit_memo_date' => now()->toDateString(),
    ]);

    $calc = app(TaxCalculator::class);
    $totals = $calc->line('1', 10000, $gst);

    $memo->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Return',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);
    $memo->refresh();

    expect($memo->subtotal_cents)->toBe(10000);
    expect($memo->tax_cents)->toBe(500);
    expect($memo->total_cents)->toBe(10500);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $gstPayable = $gst->agency->payableAccount;

    expect($ar->fresh()->balance_cents)->toBe(-10500);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(-10000);
    expect($gstPayable->fresh()->balance_cents)->toBe(-500);
});

it('voids a posted credit memo and reverses the GL entry', function () {
    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-003',
        'credit_memo_date' => now()->toDateString(),
    ]);

    $memo->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Test',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    $poster = app(CreditMemoPoster::class);
    $poster->post($memo);
    $poster->void($memo->fresh());

    $memo->refresh();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    expect($memo->status)->toBe(CreditMemoStatus::Void);
    expect($ar->fresh()->balance_cents)->toBe(0);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(0);
});

it('credit memo reduces contact AR balance when paired with an invoice', function () {
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-100',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $invoice->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-100',
        'credit_memo_date' => now()->toDateString(),
    ]);

    $memo->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Adjustment',
        'quantity' => '1',
        'unit_price_cents' => 3000,
        'line_subtotal_cents' => 3000,
        'line_tax_cents' => 0,
        'line_total_cents' => 3000,
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);

    expect($this->customer->fresh()->ar_balance_cents)->toBe(7000);
});
