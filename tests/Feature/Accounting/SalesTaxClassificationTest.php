<?php

use App\Enums\AccountSubtype;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceState;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Models\VendorCredit;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\TaxReturnPaymentPoster;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

/**
 * Which bucket each movement on an agency's payable account lands in. Only
 * Collected and Paid are tax; a payment to the agency, a filed return's own
 * adjustment entry and the opening balance move the account too, but a return
 * (and the Sales Tax report) must never count them.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->agency = $this->gst->agency;
    $this->payable = $this->agency->payableAccount;
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Post an entry with the given source and legs. The header date is passed as a
 * Carbon instance, the way the document posters write it.
 *
 * @param  list<array{0: Account, 1: int, 2: int}>  $legs  [account, debit, credit]
 */
function stcEntry(?string $sourceType, array $legs, string $date = '2026-08-15'): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.uniqid(),
        'entry_date' => CarbonImmutable::parse($date),
        'memo' => 'Test',
        'source_type' => $sourceType,
        // A document source points at a (here, absent) record; a QuickBooks
        // replay carries only the 'qbd_import' marker, never an id.
        'source_id' => $sourceType !== null && class_exists($sourceType) ? 999999 : null,
    ]);

    foreach ($legs as $i => [$account, $debit, $credit]) {
        $entry->lines()->create([
            'account_id' => $account->id,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'line_order' => $i,
        ]);
    }

    return app(JournalPoster::class)->post($entry->refresh());
}

/**
 * @return array<string, int> bucket => summed amount_cents, for August
 */
function stcBuckets(): array
{
    return app(ReportCalculator::class)
        ->salesTaxLines(test()->agency, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'))
        ->groupBy('bucket')
        ->map(fn ($lines) => (int) $lines->sum('amount_cents'))
        ->all();
}

it('treats a journal entry paying the agency from the bank as a payment', function () {
    stcEntry(null, [[$this->payable, 50000, 0], [$this->bank, 0, 50000]]);

    expect(stcBuckets())->toBe(['payment' => 50000]);
});

it('treats a QuickBooks-imported remittance as a payment', function () {
    stcEntry('qbd_import', [[$this->payable, 50000, 0], [$this->bank, 0, 50000]]);

    expect(stcBuckets())->toBe(['payment' => 50000]);
});

it('keeps the tax on an imported purchase as an input tax credit', function () {
    // An imported cheque carries its tax as an ordinary body line on the payable
    // account, beside the expense — a purchase, not a remittance.
    stcEntry('qbd_import', [[$this->expense, 10000, 0], [$this->payable, 500, 0], [$this->bank, 0, 10500]]);

    expect(stcBuckets())->toBe(['paid' => 500]);
});

it('treats a cheque coded straight to the payable account as a payment', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => '2026-08-15',
        'payee_name' => 'Receiver General',
    ]);
    $cheque->lines()->create(['account_id' => $this->payable->id, 'amount_cents' => 50000, 'tax_cents' => 0, 'line_order' => 0]);
    app(ChequePoster::class)->post($cheque);

    expect(stcBuckets())->toBe(['payment' => 50000]);
});

it('keeps the input tax credit on an ordinary cheque as paid', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2002',
        'cheque_date' => '2026-08-15',
        'payee_name' => 'Office Depot',
    ]);
    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'amount_cents' => 10000,
        'tax_code_id' => $this->gst->id,
        'tax_cents' => $this->gst->taxFor(10000),
        'line_order' => 0,
    ]);
    app(ChequePoster::class)->post($cheque);

    expect(stcBuckets())->toBe(['paid' => 500]);
});

it('treats a recorded tax payment, and its void, as payments', function () {
    $return = TaxReturn::create([
        'tax_agency_id' => $this->agency->id,
        'tax_return_no' => 'TR-PAY',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'status' => TaxReturnStatus::Filed,
    ]);
    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id,
        'payment_no' => 'TRP-001',
        'payment_date' => '2026-08-15',
        'direction' => TaxReturnPaymentDirection::Outgoing->value,
        'bank_account_id' => $this->bank->id,
        'net_amount_cents' => 50000,
    ]);
    app(TaxReturnPaymentPoster::class)->post($payment);

    expect(stcBuckets())->toBe(['payment' => 50000]);

    app(TaxReturnPaymentPoster::class)->void($payment->fresh(), CarbonImmutable::parse('2026-08-20'));

    expect(stcBuckets())->toBe(['payment' => 0]);
});

it('lowers collected for credit-memo tax and lowers paid for vendor-credit tax', function () {
    stcEntry(CreditMemo::class, [[$this->income, 10000, 0], [$this->payable, 500, 0], [$this->bank, 0, 10500]]);
    stcEntry(VendorCredit::class, [[$this->bank, 5250, 0], [$this->expense, 0, 5000], [$this->payable, 0, 250]]);

    expect(stcBuckets())->toBe(['collected' => -500, 'paid' => -250]);
});

it('classifies an expense by document, not polarity', function () {
    stcEntry(Expense::class, [[$this->expense, 10000, 0], [$this->payable, 500, 0], [$this->bank, 0, 10500]]);

    expect(stcBuckets())->toBe(['paid' => 500]);
});

it('keeps the opening-balance entry and a filed return’s adjustments off the return', function () {
    stcEntry(OpeningBalanceState::class, [[$this->income, 30000, 0], [$this->payable, 0, 30000]]);
    stcEntry(TaxReturn::class, [[$this->payable, 1000, 0], [$this->income, 0, 1000]]);

    expect(stcBuckets())->toBe(['opening' => 30000, 'adjustment' => -1000]);

    expect(app(ReportCalculator::class)->salesTaxForAgency($this->agency, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')))
        ->toBe(['collected' => 0, 'paid' => 0, 'net' => 0]);
});

it('counts only collected and paid in the agency totals', function () {
    stcEntry(null, [[$this->bank, 10500, 0], [$this->income, 0, 10000], [$this->payable, 0, 500]]);
    stcEntry(null, [[$this->payable, 20000, 0], [$this->bank, 0, 20000]]);

    expect(app(ReportCalculator::class)->salesTaxForAgency($this->agency, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')))
        ->toBe(['collected' => 500, 'paid' => 0, 'net' => 500]);
});

it('includes lines dated on the first and last day of the period', function () {
    stcEntry(null, [[$this->income, 1000, 0], [$this->payable, 0, 1000]], '2026-08-01');
    stcEntry(null, [[$this->income, 2000, 0], [$this->payable, 0, 2000]], '2026-08-31');
    stcEntry(null, [[$this->income, 4000, 0], [$this->payable, 0, 4000]], '2026-09-01');

    expect(stcBuckets())->toBe(['collected' => 3000]);
});
