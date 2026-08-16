<?php

use App\Enums\AccountSubtype;
use App\Enums\ExpenseStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\TaxCode;
use App\Services\Posting\ExpensePoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a pay-now expense: DR expense / CR bank', function () {
    $exp = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Quick Mart',
    ]);
    $exp->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Office snacks',
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ExpensePoster::class)->post($exp);
    $exp->refresh();

    expect($exp->status)->toBe(ExpenseStatus::Posted)
        ->and($exp->amount_cents)->toBe(5000)
        // Bank is debit-normal: a credit of 5000 → balance -5000
        ->and($this->bank->fresh()->balance_cents)->toBe(-5000)
        ->and($this->expense->fresh()->balance_cents)->toBe(5000);
});

it('posts an expense paid by credit card: the card liability is credited', function () {
    $card = Account::create([
        'code' => '2105',
        'name' => 'Visa',
        'subtype' => AccountSubtype::CreditCard->value,
        'type' => AccountSubtype::CreditCard->type()->value,
        'normal_balance' => AccountSubtype::CreditCard->type()->normalBalance()->value,
    ]);
    $method = PaymentMethod::create(['name' => 'Credit card', 'is_active' => true]);

    $exp = Expense::create([
        'payment_account_id' => $card->id,
        'payment_method_id' => $method->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Cloud Host',
    ]);
    $exp->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Hosting',
        'amount_cents' => 8000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ExpensePoster::class)->post($exp);
    $exp->refresh();

    expect($exp->status)->toBe(ExpenseStatus::Posted)
        ->and($exp->payment_method_id)->toBe($method->id)
        // Credit card is credit-normal: a credit of 8000 → liability +8000
        ->and($card->fresh()->balance_cents)->toBe(8000)
        ->and($this->expense->fresh()->balance_cents)->toBe(8000);
});

it('posts an expense with recoverable GST: DR expense + DR tax payable / CR bank', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $exp = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Tax Vendor',
    ]);
    $exp->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Service',
        'amount_cents' => 10000,
        'tax_code_id' => $gst->id,
        'tax_cents' => $gst->taxFor(10000),
        'line_order' => 0,
    ]);

    app(ExpensePoster::class)->post($exp);
    $exp->refresh();

    $gstPayable = $gst->agency->payableAccount;

    expect($exp->amount_cents)->toBe(10500)
        ->and($this->bank->fresh()->balance_cents)->toBe(-10500)
        ->and($this->expense->fresh()->balance_cents)->toBe(10000)
        ->and($gstPayable->fresh()->balance_cents)->toBe(-500); // ITC reduces payable
});

it('voids a posted expense and reverses the GL', function () {
    $exp = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Test',
    ]);
    $exp->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'X',
        'amount_cents' => 2500,
        'line_order' => 0,
    ]);

    $poster = app(ExpensePoster::class);
    $poster->post($exp);
    $poster->void($exp->fresh());

    $exp->refresh();

    expect($exp->status)->toBe(ExpenseStatus::Void)
        ->and($this->bank->fresh()->balance_cents)->toBe(0)
        ->and($this->expense->fresh()->balance_cents)->toBe(0);
});
