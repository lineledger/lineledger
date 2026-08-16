<?php

use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\SalesReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SalesReceipt;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\SalesReceiptPoster;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->uf = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeSalesReceipt(array $overrides = []): SalesReceipt
{
    return app(SaveSalesReceipt::class)->handle(array_merge([
        'contact_id' => test()->contact->id,
        'receipt_date' => '2026-06-01',
        'deposit_to_account_id' => test()->uf->id,
        'lines' => [[
            'account_id' => test()->income->id,
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => test()->gst->id,
        ]],
    ], $overrides));
}

it('posts a pay-now sales receipt: DR Undeposited Funds / CR income + tax, with no AR', function () {
    $receipt = makeSalesReceipt();

    expect($receipt->subtotal_cents)->toBe(10000)
        ->and($receipt->tax_cents)->toBeGreaterThan(0)
        ->and($receipt->total_cents)->toBe($receipt->subtotal_cents + $receipt->tax_cents);

    $entry = app(SalesReceiptPoster::class)->post($receipt);
    $receipt->refresh();
    $entry->load('lines');

    expect($receipt->status)->toBe(SalesReceiptStatus::Posted)
        ->and($receipt->journal_entry_id)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue();

    // Cash debit lands in Undeposited Funds for the gross total.
    $cash = $entry->lines->firstWhere('account_id', $this->uf->id);
    expect($cash->debit_cents)->toBe($receipt->total_cents)
        ->and($cash->credit_cents)->toBe(0);

    // Income is credited for the pre-tax subtotal.
    $rev = $entry->lines->firstWhere('account_id', $this->income->id);
    expect($rev->credit_cents)->toBe(10000);

    // Total tax credited equals the receipt tax.
    $taxCredit = $entry->lines->sum('credit_cents') - $rev->credit_cents;
    expect($taxCredit)->toBe($receipt->tax_cents);

    // No Accounts Receivable is ever touched.
    $arIds = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->pluck('id')->all();
    expect($entry->lines->whereIn('account_id', $arIds)->count())->toBe(0);
});

it('allows a sales receipt with no customer (walk-in cash sale)', function () {
    $receipt = makeSalesReceipt(['contact_id' => null]);

    $entry = app(SalesReceiptPoster::class)->post($receipt);

    expect($receipt->refresh()->contact_id)->toBeNull()
        ->and($entry->isBalanced())->toBeTrue();
});

it('voids a posted sales receipt and nets the cash account back to zero', function () {
    $receipt = makeSalesReceipt();
    app(SalesReceiptPoster::class)->post($receipt);

    app(SalesReceiptPoster::class)->void($receipt->refresh());

    expect($receipt->refresh()->status)->toBe(SalesReceiptStatus::Void)
        ->and($this->uf->fresh()->recomputeBalance())->toBe(0);
});

it('blocks posting into a period that is locked', function () {
    $this->company->forceFill(['lock_date' => '2026-12-31'])->save();

    $receipt = makeSalesReceipt(['receipt_date' => '2026-06-01']);

    app(SalesReceiptPoster::class)->post($receipt);
})->throws(PeriodLockedException::class);
