<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\TaxCode;
use App\Services\Posting\ChequePoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a direct-expense cheque: DR expense / CR bank', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Quick Mart',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Office snacks',
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);
    $cheque->refresh();

    expect($cheque->status)->toBe(ChequeStatus::Posted);
    expect($cheque->amount_cents)->toBe(5000);

    // Bank is debit-normal: a credit of 5000 → balance -5000
    expect($this->bank->fresh()->balance_cents)->toBe(-5000);
    expect($this->expense->fresh()->balance_cents)->toBe(5000);
});

it('posts a cheque with recoverable GST: DR expense + DR tax payable / CR bank', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1002',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Tax Vendor',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Service',
        'amount_cents' => 10000,
        'tax_code_id' => $gst->id,
        'tax_cents' => $gst->taxFor(10000),
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);
    $cheque->refresh();

    $gstPayable = $gst->agency->payableAccount;

    expect($cheque->amount_cents)->toBe(10500);
    expect($this->bank->fresh()->balance_cents)->toBe(-10500);
    expect($this->expense->fresh()->balance_cents)->toBe(10000);
    expect($gstPayable->fresh()->balance_cents)->toBe(-500); // ITC reduces payable
});

it('voids a posted cheque and reverses the GL', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1003',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Test',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'X',
        'amount_cents' => 2500,
        'line_order' => 0,
    ]);

    $poster = app(ChequePoster::class);
    $poster->post($cheque);
    $poster->void($cheque->fresh());

    $cheque->refresh();

    expect($cheque->status)->toBe(ChequeStatus::Void);
    expect($this->bank->fresh()->balance_cents)->toBe(0);
    expect($this->expense->fresh()->balance_cents)->toBe(0);
});

it('carries the line description onto the expense leg, leaving the tax and bank legs alone', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1003',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Tax Vendor',
    ]);
    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Printer toner',
        'amount_cents' => 10000,
        'tax_code_id' => $gst->id,
        'tax_cents' => $gst->taxFor(10000),
        'line_order' => 0,
    ]);

    $lines = app(ChequePoster::class)->post($cheque)->lines;

    expect($lines->firstWhere('account_id', $this->expense->id)->memo)->toBe('Printer toner')
        ->and($lines->firstWhere('account_id', $gst->agency->payable_account_id)->memo)->toBe('Input tax credit')
        ->and($lines->firstWhere('account_id', $this->bank->id)->memo)->toBe('Cheque 1003');
});

it('joins the descriptions of lines that share an expense leg', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1004',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Cafe',
    ]);

    foreach (['Coffee', '  ', 'Muffins', 'Coffee'] as $i => $description) {
        $cheque->lines()->create([
            'account_id' => $this->expense->id,
            'description' => $description,
            'amount_cents' => 500,
            'tax_cents' => 0,
            'line_order' => $i,
        ]);
    }

    $legs = app(ChequePoster::class)->post($cheque)->lines->where('account_id', $this->expense->id);

    expect($legs)->toHaveCount(1)
        ->and((int) $legs->first()->debit_cents)->toBe(2000)
        ->and($legs->first()->memo)->toBe('Coffee; Muffins');
});

it('rewrites the expense leg memo when a posted cheque line description is edited', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1005',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Quick Mart',
    ]);
    $line = $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Office snacks',
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    $line->update(['description' => 'Office snacks and coffee']);

    $lines = app(ChequePoster::class)->repost($cheque->fresh())->lines;

    expect($lines->firstWhere('account_id', $this->expense->id)->memo)->toBe('Office snacks and coffee');
});
