<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->usdCurrency = $this->company->currencies()->where('currency_code', 'USD')->first();

    $this->customer = Contact::create([
        'display_name' => 'US Customer',
        'is_customer' => true,
        'currency_code' => 'USD',
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Post a USD invoice of $foreignTotal cents at the given rate (one income line,
 * no tax). Returns the refreshed invoice.
 */
function postUsdInvoice(int $foreignTotal, string $rate, string $no = 'INV-USD'): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => $no,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency_code' => 'USD',
        'fx_rate' => $rate,
    ]);

    $invoice->lines()->create([
        'account_id' => test()->incomeAccount->id,
        'description' => 'Export sale',
        'quantity' => '1',
        'unit_price_cents' => $foreignTotal,
        'line_subtotal_cents' => $foreignTotal,
        'line_tax_cents' => 0,
        'line_total_cents' => $foreignTotal,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

it('posts a foreign invoice in home cents with the foreign amount as a memo', function () {
    $invoice = postUsdInvoice(100_000, '1.35'); // 1,000.00 USD @ 1.35

    $entry = $invoice->journalEntry;
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebitsCents())->toBe(135_000)
        ->and($invoice->home_total_cents)->toBe(135_000);

    $arControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ar_account_id);
    expect($arControl->fresh()->balance_cents)->toBe(135_000)      // home
        ->and($arControl->fresh()->foreignBalanceCents())->toBe(100_000); // USD

    expect($this->incomeAccount->fresh()->balance_cents)->toBe(135_000);

    $arLine = $entry->lines->firstWhere('account_id', $arControl->id);
    expect($arLine->debit_cents)->toBe(135_000)
        ->and($arLine->foreign_debit_cents)->toBe(100_000)
        ->and($arLine->currency_code)->toBe('USD')
        ->and((float) $arLine->fx_rate)->toBe(1.35);
});

it('realizes an exchange gain when the receipt rate is higher than the invoice rate', function () {
    $invoice = postUsdInvoice(100_000, '1.35');

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-USD',
        'receipt_date' => '2026-04-01',
        'deposit_to_account_id' => $this->bank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.40',
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100_000]);

    app(ReceiptPoster::class)->post($receipt);

    $entry = $receipt->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue();

    // Bank received the home value at the payment rate.
    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    expect($bankLine->debit_cents)->toBe(140_000);

    // The foreign AR control is fully cleared in both home and foreign terms.
    $arControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ar_account_id);
    expect($arControl->fresh()->balance_cents)->toBe(0)
        ->and($arControl->fresh()->foreignBalanceCents())->toBe(0);

    // 50.00 CAD realized gain credited to Exchange Gain/Loss.
    $gainLine = $entry->lines->firstWhere('account_id', $this->company->exchange_gain_loss_account_id);
    expect($gainLine->credit_cents)->toBe(5_000)
        ->and($gainLine->debit_cents)->toBe(0);
});

it('realizes an exchange loss when the receipt rate is lower than the invoice rate', function () {
    $invoice = postUsdInvoice(100_000, '1.35');

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-USD2',
        'receipt_date' => '2026-04-01',
        'deposit_to_account_id' => $this->bank->id,
        'amount_cents' => 100_000,
        'currency_code' => 'USD',
        'fx_rate' => '1.30',
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100_000]);

    app(ReceiptPoster::class)->post($receipt);

    $entry = $receipt->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue();

    $arControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ar_account_id);
    expect($arControl->fresh()->balance_cents)->toBe(0);

    // 50.00 CAD realized loss debited to Exchange Gain/Loss.
    $lossLine = $entry->lines->firstWhere('account_id', $this->company->exchange_gain_loss_account_id);
    expect($lossLine->debit_cents)->toBe(5_000)
        ->and($lossLine->credit_cents)->toBe(0);
});

it('posts a foreign credit memo crediting the foreign AR control', function () {
    $memo = CreditMemo::create([
        'contact_id' => $this->customer->id,
        'credit_memo_no' => 'CM-USD',
        'credit_memo_date' => '2026-03-15',
        'currency_code' => 'USD',
        'fx_rate' => '1.35',
    ]);

    $memo->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Return',
        'quantity' => '1',
        'unit_price_cents' => 40_000,
        'line_subtotal_cents' => 40_000,
        'line_tax_cents' => 0,
        'line_total_cents' => 40_000,
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);

    $entry = $memo->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue()
        ->and($memo->home_total_cents)->toBe(54_000); // 400 USD @1.35

    $arControl = Account::withoutGlobalScopes()->find($this->usdCurrency->ar_account_id);
    $arLine = $entry->lines->firstWhere('account_id', $arControl->id);
    expect($arLine->credit_cents)->toBe(54_000)
        ->and($arLine->foreign_credit_cents)->toBe(40_000);
});

it('rounds revenue legs so the home entry balances exactly', function () {
    // An awkward rate that does not divide evenly across legs.
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-USD-ROUND',
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency_code' => 'USD',
        'fx_rate' => '1.23456',
    ]);

    foreach ([33_333, 33_333, 33_334] as $i => $cents) {
        $invoice->lines()->create([
            'account_id' => $this->incomeAccount->id,
            'description' => 'Line '.$i,
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => $i,
        ]);
    }

    app(InvoicePoster::class)->post($invoice);

    expect($invoice->refresh()->journalEntry->isBalanced())->toBeTrue();
});
