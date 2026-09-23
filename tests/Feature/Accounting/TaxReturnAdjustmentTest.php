<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Tax\SaveTaxReturn;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\TaxReturnStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\TaxReturnOutOfBalanceException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Services\Posting\BillPoster;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Reporting\ReportCalculator;
use App\Services\Tax\TaxReturnCalculator;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A tax return's adjustments and its reconciliation to the agency's payable
 * account, worked through the filing that prompted them: an August return with
 * 1,570.80 collected, 20.65 of ITCs and a 100.00 collector's commission, filed
 * against a payable account that opened August at 1,781.71 and saw a 1,762.81
 * remittance for July — leaving 18.90 of July underpaid.
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
    $this->commissionIncome = Account::create([
        'code' => '4960',
        'name' => 'Tax Commission Income',
        'subtype' => AccountSubtype::OtherIncome->value,
        'type' => AccountSubtype::OtherIncome->type()->value,
        'normal_balance' => AccountSubtype::OtherIncome->type()->normalBalance()->value,
    ]);
    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Vendor Co', 'is_vendor' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  list<array{0: Account, 1: int, 2: int}>  $legs  [account, debit, credit]
 */
function traPostJournal(string $date, array $legs): JournalEntry
{
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => $date,
        'memo' => 'Test entry',
        'lines' => array_map(fn (array $leg) => [
            'account_id' => $leg[0]->id,
            'debit_cents' => $leg[1],
            'credit_cents' => $leg[2],
        ], $legs),
    ]);

    return app(JournalPoster::class)->post($entry);
}

function traPostInvoice(string $date, int $subtotalCents): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, test()->gst);

    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

function traPostBill(string $date, int $subtotalCents): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-'.uniqid(),
        'bill_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, test()->gst);

    $bill->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

/**
 * A cheque paying the agency: one line coded straight to its payable account.
 */
function traPostRemittanceCheque(string $date, int $cents): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => (string) random_int(10000, 99999),
        'cheque_date' => $date,
        'payee_name' => 'Receiver General',
    ]);

    $cheque->lines()->create([
        'account_id' => test()->payable->id,
        'description' => 'July remittance',
        'amount_cents' => $cents,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    return $cheque->fresh();
}

/**
 * The worked example: July closes at 1,781.71 owing, August pays 1,762.81 of it,
 * collects 1,570.80 (5% of 31,416.00) and claims 20.65 (5% of 413.00).
 */
function traAugustScenario(): void
{
    traPostJournal('2026-07-31', [[test()->income, 178171, 0], [test()->payable, 0, 178171]]);
    traPostRemittanceCheque('2026-08-15', 176281);
    traPostInvoice('2026-08-10', 3141600);
    traPostBill('2026-08-20', 41300);
}

/**
 * @param  list<array{kind: string, account_id: int, amount_cents: int, memo?: ?string}>  $adjustments
 */
function traDraft(array $adjustments = []): TaxReturn
{
    return app(SaveTaxReturn::class)->handle([
        'tax_agency_id' => test()->agency->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'adjustments' => $adjustments,
    ]);
}

function traCommission(): array
{
    return ['kind' => 'other', 'account_id' => test()->commissionIncome->id, 'amount_cents' => -10000, 'memo' => 'Collector’s commission'];
}

function traCarriedForward(): array
{
    return ['kind' => 'other', 'account_id' => test()->payable->id, 'amount_cents' => 1890, 'memo' => 'July underpayment'];
}

it('reconciles the return to the payable account and finds the 18.90 left from July', function () {
    traAugustScenario();

    $figures = app(TaxReturnCalculator::class)->forReturn(traDraft([traCommission()]));
    $r = $figures->reconciliation;

    expect($figures->collectedCents)->toBe(157080)
        ->and($figures->paidCents)->toBe(2065)
        ->and($figures->otherAdjustmentsCents)->toBe(-10000)
        ->and($figures->netCents)->toBe(145015)
        ->and($r['opening_cents'])->toBe(178171)
        ->and($r['payments_cents'])->toBe(-176281)
        ->and($r['collected_cents'])->toBe(157080)
        ->and($r['itc_cents'])->toBe(-2065)
        ->and($r['unclassified_cents'])->toBe(0)
        ->and($r['closing_cents'])->toBe(156905)
        ->and($r['to_post_cents'])->toBe(-10000)
        ->and($r['projected_cents'])->toBe(146905)
        ->and($r['difference_cents'])->toBe(1890);

    // The remittance cheque is a payment, never an input tax credit.
    expect($figures->returnLines()->pluck('source_type'))->not->toContain(Cheque::class)
        ->and($figures->ledgerOnlyLines()->pluck('bucket')->all())->toContain('payment');
});

it('refuses to file a return that does not reconcile unless the difference is accepted', function () {
    traAugustScenario();
    $return = traDraft([traCommission()]);

    expect(fn () => app(TaxReturnFiler::class)->file($return))
        ->toThrow(TaxReturnOutOfBalanceException::class);

    // Accepting a different amount than the live difference is refused too.
    expect(fn () => app(TaxReturnFiler::class)->file($return, 1000))
        ->toThrow(TaxReturnOutOfBalanceException::class);

    expect($return->fresh()->status)->toBe(TaxReturnStatus::Draft)
        ->and(JournalEntry::query()->where('source_type', TaxReturn::class)->exists())->toBeFalse();

    $filed = app(TaxReturnFiler::class)->file($return, 1890);

    expect($filed->status)->toBe(TaxReturnStatus::Filed)
        ->and($filed->net_cents)->toBe(145015)
        ->and($filed->reconciliation['accepted_difference_cents'])->toBe(1890);
});

it('files once the carried-forward 18.90 is added, posting the commission against the payable account', function () {
    traAugustScenario();
    $return = traDraft([traCommission(), traCarriedForward()]);

    expect(app(TaxReturnCalculator::class)->forReturn($return)->differenceCents())->toBe(0);

    $filed = app(TaxReturnFiler::class)->file($return);

    expect($filed->status)->toBe(TaxReturnStatus::Filed)
        ->and($filed->collected_cents)->toBe(157080)
        ->and($filed->paid_cents)->toBe(2065)
        ->and($filed->other_adjustments_cents)->toBe(-8110)
        ->and($filed->net_cents)->toBe(146905)
        ->and($filed->reconciliation['difference_cents'])->toBe(0)
        ->and($filed->lines)->toHaveCount(2);

    // Only the commission posts — the 18.90 is already in the payable account.
    $entry = $filed->adjustmentJournalEntry;

    expect($entry)->not->toBeNull()
        ->and($entry->is_posted)->toBeTrue()
        ->and($entry->entry_date->toDateString())->toBe('2026-08-31')
        ->and($entry->source_type)->toBe(TaxReturn::class)
        ->and($entry->lines)->toHaveCount(2);

    $payableLeg = $entry->lines->firstWhere('account_id', $this->payable->id);
    $commissionLeg = $entry->lines->firstWhere('account_id', $this->commissionIncome->id);

    expect((int) $payableLeg->debit_cents)->toBe(10000)
        ->and((int) $commissionLeg->credit_cents)->toBe(10000);

    // The payable account now holds exactly what the return says is owed.
    expect(app(ReportCalculator::class)->balanceAsOf($this->payable->fresh(), CarbonImmutable::parse('2026-08-31')))
        ->toBe(146905);

    $audit = AccountingAuditLog::query()->where('action', 'tax_return.filed')->where('auditable_id', $return->id)->firstOrFail();

    expect($audit->payload['adjustments'])->toHaveCount(2)
        ->and($audit->payload['reconciliation']['difference_cents'])->toBe(0)
        ->and($audit->payload['adjustment_journal_entry_id'])->toBe($entry->id);
});

it('posts nothing when every adjustment is on the payable account', function () {
    traPostInvoice('2026-08-10', 10000);
    traPostJournal('2026-07-31', [[$this->income, 1890, 0], [$this->payable, 0, 1890]]);

    $filed = app(TaxReturnFiler::class)->file(traDraft([traCarriedForward()]));

    expect($filed->adjustment_journal_entry_id)->toBeNull()
        ->and($filed->net_cents)->toBe(500 + 1890)
        ->and(JournalEntry::query()->where('source_type', TaxReturn::class)->exists())->toBeFalse();
});

it('raises the net owing with a correction coded to another account', function () {
    traPostInvoice('2026-08-10', 10000);

    $filed = app(TaxReturnFiler::class)->file(traDraft([
        ['kind' => 'collected', 'account_id' => $this->income->id, 'amount_cents' => 250, 'memo' => 'Tax missed on a cash sale'],
    ]));

    $entry = $filed->adjustmentJournalEntry;

    expect($filed->collected_cents)->toBe(750)
        ->and($filed->net_cents)->toBe(750)
        ->and((int) $entry->lines->firstWhere('account_id', $this->income->id)->debit_cents)->toBe(250)
        ->and((int) $entry->lines->firstWhere('account_id', $this->payable->id)->credit_cents)->toBe(250);
});

it('refuses to file when the adjustment entry would land in a locked period', function () {
    traAugustScenario();
    $return = traDraft([traCommission(), traCarriedForward()]);
    $this->company->update(['lock_date' => '2026-08-31']);

    expect(fn () => app(TaxReturnFiler::class)->file($return))->toThrow(PeriodLockedException::class);
    expect($return->fresh()->status)->toBe(TaxReturnStatus::Draft);
});

it('reverses the adjustment entry on its own date when the return is voided, so re-filing starts clean', function () {
    traAugustScenario();
    $return = traDraft([traCommission(), traCarriedForward()]);
    $filed = app(TaxReturnFiler::class)->file($return);
    $entry = $filed->adjustmentJournalEntry;

    app(TaxReturnFiler::class)->void($filed, 'Amending');

    $reversal = JournalEntry::query()->where('reverses_entry_id', $entry->id)->firstOrFail();

    expect($entry->fresh()->isVoided())->toBeTrue()
        ->and($reversal->entry_date->toDateString())->toBe('2026-08-31');

    // A fresh draft for the same period reconciles exactly as the first one did.
    $refile = traDraft([traCommission(), traCarriedForward()]);
    $figures = app(TaxReturnCalculator::class)->forReturn($refile);

    expect($figures->differenceCents())->toBe(0)
        ->and($figures->reconciliation['prior_adjustments_cents'])->toBe(0)
        ->and(app(TaxReturnFiler::class)->file($refile)->net_cents)->toBe(146905);
});

it('voids a return whose adjustment entry was already reversed elsewhere', function () {
    traAugustScenario();
    $filed = app(TaxReturnFiler::class)->file(traDraft([traCommission(), traCarriedForward()]));

    app(JournalPoster::class)->void($filed->adjustmentJournalEntry, CarbonImmutable::parse('2026-08-31'));
    app(TaxReturnFiler::class)->void($filed, 'Amending');

    expect($filed->fresh()->status)->toBe(TaxReturnStatus::Void)
        ->and(JournalEntry::query()->where('reverses_entry_id', $filed->adjustment_journal_entry_id)->count())->toBe(1);
});

it('stores provisional totals on a saved draft', function () {
    traAugustScenario();

    $draft = traDraft([traCommission(), traCarriedForward()]);

    expect($draft->status)->toBe(TaxReturnStatus::Draft)
        ->and($draft->collected_cents)->toBe(157080)
        ->and($draft->paid_cents)->toBe(2065)
        ->and($draft->other_adjustments_cents)->toBe(-8110)
        ->and($draft->net_cents)->toBe(146905)
        ->and($draft->adjustments)->toHaveCount(2);
});

it('leaves adjustments and exclusions alone when a save omits them', function () {
    traPostInvoice('2026-08-10', 10000);
    $draft = traDraft([traCommission()]);
    $draft->forceFill(['excluded_journal_line_ids' => [123]])->save();

    app(SaveTaxReturn::class)->handle([
        'tax_agency_id' => $this->agency->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'notes' => 'Edited',
    ], $draft);

    $draft->refresh();

    expect($draft->adjustments)->toHaveCount(1)
        ->and($draft->excluded_journal_line_ids)->toBe([123])
        ->and($draft->notes)->toBe('Edited');
});

it('rejects an adjustment with no amount or coded to a receivable', function () {
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->firstOrFail();

    expect(fn () => traDraft([['kind' => 'other', 'account_id' => $ar->id, 'amount_cents' => 0]]))
        ->toThrow(ValidationException::class);

    try {
        traDraft([['kind' => 'other', 'account_id' => $ar->id, 'amount_cents' => 0]]);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['adjustments.0.account_id', 'adjustments.0.amount_cents']);
    }
});
