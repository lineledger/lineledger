<?php

use App\Enums\AccountSubtype;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $this->customer = Contact::create(['display_name' => 'Pay Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // Create + post an invoice, then a receipt sitting in undeposited funds
    $inv = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-D-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 15000,
        'line_subtotal_cents' => 15000,
        'line_tax_cents' => 0,
        'line_total_cents' => 15000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    $this->receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-D-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undep->id,
        'amount_cents' => 15000,
    ]);
    $this->receipt->applications()->create(['invoice_id' => $inv->fresh()->id, 'amount_cents' => 15000]);
    app(ReceiptPoster::class)->post($this->receipt->fresh('applications'));
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('moves money from Undeposited Funds to Bank on a receipt-only deposit', function () {
    expect($this->undep->fresh()->balance_cents)->toBe(15000);
    expect($this->bank->fresh()->balance_cents)->toBe(0);

    $dep = Deposit::create([
        'bank_account_id' => $this->bank->id,
        'deposit_no' => 'DEP-001',
        'deposit_date' => now()->toDateString(),
    ]);

    $dep->lines()->create([
        'customer_receipt_id' => $this->receipt->id,
        'description' => 'Receipt REC-D-1',
        'amount_cents' => 15000,
        'line_order' => 0,
    ]);

    app(DepositPoster::class)->post($dep->fresh('lines'));

    $dep->refresh();

    expect($dep->status)->toBe(DepositStatus::Posted);
    expect($dep->amount_cents)->toBe(15000);
    expect($this->undep->fresh()->balance_cents)->toBe(0);
    expect($this->bank->fresh()->balance_cents)->toBe(15000);
});

it('combines receipt-source and "other" lines in a single deposit', function () {
    $ownerContrib = Account::query()->where('name', 'Owner Contributions')->first();

    $dep = Deposit::create([
        'bank_account_id' => $this->bank->id,
        'deposit_no' => 'DEP-002',
        'deposit_date' => now()->toDateString(),
    ]);

    $dep->lines()->create([
        'customer_receipt_id' => $this->receipt->id,
        'description' => 'Receipt',
        'amount_cents' => 15000,
        'line_order' => 0,
    ]);

    $dep->lines()->create([
        'account_id' => $ownerContrib->id,
        'description' => 'Owner cash injection',
        'amount_cents' => 50000,
        'line_order' => 1,
    ]);

    app(DepositPoster::class)->post($dep->fresh('lines'));

    expect($dep->fresh()->amount_cents)->toBe(65000);
    expect($this->bank->fresh()->balance_cents)->toBe(65000);
    expect($this->undep->fresh()->balance_cents)->toBe(0);
    expect($ownerContrib->fresh()->balance_cents)->toBe(50000);
});

it('voiding a deposit reverses the GL and releases receipts (they reappear for next deposit)', function () {
    $dep = Deposit::create([
        'bank_account_id' => $this->bank->id,
        'deposit_no' => 'DEP-003',
        'deposit_date' => now()->toDateString(),
    ]);

    $dep->lines()->create([
        'customer_receipt_id' => $this->receipt->id,
        'description' => 'Receipt',
        'amount_cents' => 15000,
        'line_order' => 0,
    ]);

    $poster = app(DepositPoster::class);
    $poster->post($dep->fresh('lines'));
    $poster->void($dep->fresh());

    expect($dep->fresh()->status)->toBe(DepositStatus::Void);
    // Money is back in Undeposited Funds (reversed)
    expect($this->undep->fresh()->balance_cents)->toBe(15000);
    expect($this->bank->fresh()->balance_cents)->toBe(0);
});
