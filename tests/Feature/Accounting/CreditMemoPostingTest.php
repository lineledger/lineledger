<?php

use App\Actions\Sales\SaveCreditMemo;
use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\JournalLine;
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

it('credits the account of a negative-quantity line and balances the entry', function () {
    // Refund a premium service, re-charge the standard one the customer got
    // instead (quantity -1), and return the GST and a card fee on the difference.
    $accounts = Account::query()->whereIn('code', ['4000', '4100', '4900', '2200'])->get()->keyBy('code');

    $memo = app(SaveCreditMemo::class)->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [
            ['description' => 'Premium service', 'account_id' => $accounts['4000']->id, 'quantity' => '1', 'unit_price_cents' => 99500],
            ['description' => 'Standard service', 'account_id' => $accounts['4100']->id, 'quantity' => '-1', 'unit_price_cents' => 19500],
            ['description' => 'GST', 'account_id' => $accounts['2200']->id, 'quantity' => '1', 'unit_price_cents' => 4000],
            ['description' => 'Card processing fee', 'account_id' => $accounts['4900']->id, 'quantity' => '1', 'unit_price_cents' => 2016],
        ],
    ]);

    $poster = app(CreditMemoPoster::class);
    $entry = $poster->post($memo);
    $lines = $entry->lines;
    $line = fn (int $accountId): JournalLine => $lines->firstWhere('account_id', $accountId);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    expect($memo->fresh()->total_cents)->toBe(86016)
        ->and((int) $line($accounts['4000']->id)->debit_cents)->toBe(99500)
        ->and((int) $line($accounts['4100']->id)->debit_cents)->toBe(0)
        ->and((int) $line($accounts['4100']->id)->credit_cents)->toBe(19500)
        ->and((int) $line($accounts['2200']->id)->debit_cents)->toBe(4000)
        ->and((int) $line($accounts['4900']->id)->debit_cents)->toBe(2016)
        ->and((int) $line($ar->id)->credit_cents)->toBe(86016)
        ->and((int) $lines->sum('debit_cents'))->toBe((int) $lines->sum('credit_cents'))
        ->and($lines->every(fn (JournalLine $l): bool => $l->debit_cents >= 0 && $l->credit_cents >= 0))->toBeTrue();

    // The standard service shows as earned: a credit on its income account.
    expect($accounts['4100']->fresh()->balance_cents)->toBe(19500)
        ->and($ar->fresh()->balance_cents)->toBe(-86016);

    $poster->void($memo->fresh());

    $reversal = $entry->fresh()->reversedBy;
    $reversed = $reversal->lines->firstWhere('account_id', $accounts['4100']->id);

    expect($reversal->isBalanced())->toBeTrue()
        ->and((int) $reversed->debit_cents)->toBe(19500)
        ->and((int) $reversed->credit_cents)->toBe(0);

    foreach ([...$accounts->all(), $ar] as $account) {
        expect($account->fresh()->balance_cents)->toBe(0);
    }
});

it('credits tax payable for a taxed negative-quantity line', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $services = Account::query()->where('subtype', AccountSubtype::Income->value)->where('id', '!=', $this->incomeAccount->id)->firstOrFail();

    $memo = app(SaveCreditMemo::class)->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $this->incomeAccount->id, 'quantity' => '1', 'unit_price_cents' => 10000],
            ['account_id' => $services->id, 'quantity' => '-1', 'unit_price_cents' => 4000, 'tax_code_id' => $gst->id],
        ],
    ]);

    $entry = app(CreditMemoPoster::class)->post($memo);
    $payable = $entry->lines->firstWhere('account_id', $gst->agency->payable_account_id);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    // 100.00 - 40.00 - 2.00 GST = 58.00 credited to the customer.
    expect($memo->fresh()->total_cents)->toBe(5800)
        ->and((int) $payable->debit_cents)->toBe(0)
        ->and((int) $payable->credit_cents)->toBe(200)
        ->and((int) $entry->lines->firstWhere('account_id', $services->id)->credit_cents)->toBe(4000)
        ->and((int) $entry->lines->firstWhere('account_id', $ar->id)->credit_cents)->toBe(5800)
        ->and($entry->isBalanced())->toBeTrue();
});

it('reposts a credit memo edited to carry a negative-quantity line', function () {
    $services = Account::query()->where('subtype', AccountSubtype::Income->value)->where('id', '!=', $this->incomeAccount->id)->firstOrFail();
    $save = app(SaveCreditMemo::class);
    $poster = app(CreditMemoPoster::class);

    $memo = $save->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [['account_id' => $this->incomeAccount->id, 'quantity' => '1', 'unit_price_cents' => 10000]],
    ]);
    $poster->post($memo);

    $memo = $save->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $this->incomeAccount->id, 'quantity' => '1', 'unit_price_cents' => 10000],
            ['account_id' => $services->id, 'quantity' => '-1', 'unit_price_cents' => 2500],
        ],
    ], $memo->fresh());

    $entry = $poster->repost($memo);

    expect($memo->fresh()->total_cents)->toBe(7500)
        ->and((int) $entry->lines->firstWhere('account_id', $services->id)->credit_cents)->toBe(2500)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($this->customer->fresh()->ar_balance_cents)->toBe(-7500);
});
