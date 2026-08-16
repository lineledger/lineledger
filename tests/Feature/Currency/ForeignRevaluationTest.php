<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\RunHomeCurrencyAdjustment;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->usdCurrency = $this->company->currencies()->where('currency_code', 'USD')->first();
    $this->customer = Contact::create(['display_name' => 'US Co', 'is_customer' => true, 'currency_code' => 'USD']);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // Open USD AR of 1,000 USD booked at 1.35 → carrying 1,350 CAD.
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id, 'invoice_no' => 'INV-1',
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Sale', 'quantity' => '1',
        'unit_price_cents' => 100_000, 'line_subtotal_cents' => 100_000,
        'line_tax_cents' => 0, 'line_total_cents' => 100_000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function homeBalanceAsOf(int $accountId, string $date): int
{
    return (int) DB::table('journal_lines')
        ->where('account_id', $accountId)
        ->where('is_posted', true)
        ->where('entry_date', '<=', $date)
        ->sum(DB::raw('debit_cents - credit_cents'));
}

it('revalues an open foreign balance to the closing rate and reverses it next day', function () {
    $asOf = CarbonImmutable::parse('2026-03-31');

    $revaluation = app(RunHomeCurrencyAdjustment::class)->handle($this->company, $asOf, ['USD' => '1.42']);

    expect($revaluation)->not->toBeNull()
        ->and($revaluation->journal_entry_id)->not->toBeNull()
        ->and($revaluation->reversal_entry_id)->not->toBeNull()
        ->and($revaluation->rate_snapshot['USD'])->toBe('1.42');

    $arId = $this->usdCurrency->ar_account_id;

    // As of period end the AR control is revalued: 1,000 USD @1.42 = 1,420 CAD.
    expect(homeBalanceAsOf($arId, '2026-03-31'))->toBe(142_000);

    // The next day the adjustment is reversed back to the carrying value.
    expect(homeBalanceAsOf($arId, '2026-04-01'))->toBe(135_000);

    // The +70 CAD unrealized gain lands on the Unrealized Gain/Loss account at period end.
    $unrealizedId = $this->company->unrealized_gain_loss_account_id;
    expect(homeBalanceAsOf($unrealizedId, '2026-03-31'))->toBe(-7_000) // credit = gain
        ->and(homeBalanceAsOf($unrealizedId, '2026-04-01'))->toBe(0);

    // Both entries balance in home cents.
    expect($revaluation->journalEntry->isBalanced())->toBeTrue()
        ->and($revaluation->reversalEntry->isBalanced())->toBeTrue();
});

it('does nothing when the closing rate matches the carrying rate', function () {
    $revaluation = app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.35']);

    expect($revaluation)->toBeNull();
});

it('refuses a duplicate revaluation for the same date', function () {
    app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.42']);
    app(RunHomeCurrencyAdjustment::class)->handle($this->company, CarbonImmutable::parse('2026-03-31'), ['USD' => '1.50']);
})->throws(DomainException::class);
