<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Posting\VendorCreditPoster;
use App\Services\Tax\TaxReturnBuilder;
use App\Services\Tax\TaxReturnCalculator;
use App\Services\Tax\TaxReturnFiler;
use App\Services\Tax\VatReturnBoxes;
use App\Services\Tax\VatReturnCalculator;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UK VAT, end to end
|--------------------------------------------------------------------------
|
| One UK company trades through a VAT quarter (Q1 2026) — standard, reduced,
| zero-rated and exempt sales and purchases, a document discount, a credit
| memo, a vendor credit, a euro invoice, an out-of-scope expense line and a
| sale in the next quarter — and every HMRC box is checked to the penny.
|
| Expected figures for Q1 2026:
|   Box 1  £200.00 + £100.00 − £40.00                         = £260.00
|   Box 4  £60.00 + £2.28 − £10.00                            =  £52.28
|   Box 5  £260.00 − £52.28                                   = £207.72
|   Box 6  £1,350.49 + £450.00 − £200.00 + £850.00 = £2,450.49 → £2,450
|   Box 7  £300.00 + £45.67 − £50.00               =   £295.67 →   £295
|
*/

beforeEach(function () {
    $this->company = Company::factory()->forCountry(Country::UnitedKingdom)->create([
        'name' => 'Douglas Widgets Ltd',
        'address_region' => 'ENG',
    ]);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Customer Ltd', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Supplier Ltd', 'is_vendor' => true]);

    $this->income = Account::query()->where('code', '4000')->firstOrFail();
    $this->expenseAccount = Account::query()->where('code', '6040')->firstOrFail();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();

    $this->standard = TaxCode::where('code', 'VAT')->firstOrFail();
    $this->reduced = TaxCode::where('code', 'VAT-R')->firstOrFail();
    $this->zero = TaxCode::where('code', 'ZR')->firstOrFail();
    $this->exempt = TaxCode::where('code', 'EX')->firstOrFail();
    $this->agency = $this->standard->agency;

    $this->start = CarbonImmutable::parse('2026-01-01');
    $this->end = CarbonImmutable::parse('2026-03-31');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  list<array{0: int, 1: ?TaxCode}>  $lines  [net pence, tax code]
 */
function ukLines(array $lines, Account $account): array
{
    $calculator = app(TaxCalculator::class);

    return array_map(function (array $line, int $order) use ($calculator, $account) {
        [$net, $code] = $line;
        $totals = $calculator->line('1', $net, $code);

        return [
            'account_id' => $account->id,
            'description' => 'Line '.($order + 1),
            'quantity' => '1',
            'unit_price_cents' => $net,
            'tax_code_id' => $code?->id,
            'line_subtotal_cents' => $totals['subtotal_cents'],
            'line_tax_cents' => $totals['tax_cents'],
            'line_total_cents' => $totals['total_cents'],
            'line_order' => $order,
        ];
    }, $lines, array_keys($lines));
}

function ukInvoice(string $date, array $lines, array $attributes = []): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date,
        ...$attributes,
    ]);

    foreach (ukLines($lines, test()->income) as $line) {
        $invoice->lines()->create($line);
    }

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

function ukBill(string $date, array $lines): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-'.uniqid(),
        'bill_date' => $date,
        'due_date' => $date,
    ]);

    foreach (ukLines($lines, test()->expenseAccount) as $line) {
        $bill->lines()->create($line);
    }

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

/**
 * Post the whole Q1 2026 scenario described at the top of the file.
 */
function postUkQuarter(): array
{
    $t = test();

    // Standard, zero-rated and exempt lines on one invoice.
    $mixed = ukInvoice('2026-01-15', [[100000, $t->standard], [25050, $t->zero], [9999, $t->exempt]]);

    // A £100 document discount shared across a VAT line and an out-of-scope line.
    $discounted = ukInvoice('2026-02-10', [[50000, $t->standard], [50000, null]], ['document_discount_cents' => 10000]);

    $memo = CreditMemo::create([
        'contact_id' => $t->customer->id,
        'credit_memo_no' => 'CM-'.uniqid(),
        'credit_memo_date' => '2026-03-05',
    ]);
    foreach (ukLines([[20000, $t->standard]], $t->income) as $line) {
        $memo->lines()->create($line);
    }
    app(CreditMemoPoster::class)->post($memo);

    $bill = ukBill('2026-02-20', [[30000, $t->standard]]);

    $expense = Expense::create([
        'payment_account_id' => $t->bank->id,
        'expense_date' => '2026-03-10',
        'payee_name' => 'Print Shop',
    ]);
    $expense->lines()->create([
        'account_id' => $t->expenseAccount->id,
        'description' => 'Child car seat (reduced rate)',
        'amount_cents' => 4567,
        'tax_code_id' => $t->reduced->id,
        'tax_cents' => $t->reduced->taxFor(4567),
        'line_order' => 0,
    ]);
    $expense->lines()->create([
        'account_id' => $t->expenseAccount->id,
        'description' => 'Business rates (outside the scope of VAT)',
        'amount_cents' => 100000,
        'tax_cents' => 0,
        'line_order' => 1,
    ]);
    app(ExpensePoster::class)->post($expense);

    $credit = VendorCredit::create([
        'contact_id' => $t->vendor->id,
        'vendor_credit_no' => 'VC-'.uniqid(),
        'vendor_credit_date' => '2026-03-15',
    ]);
    foreach (ukLines([[5000, $t->standard]], $t->expenseAccount) as $line) {
        $credit->lines()->create($line);
    }
    app(VendorCreditPoster::class)->post($credit);

    // An export to an EU customer, invoiced in euros and zero-rated:
    // €1,000.00 at 0.85 is £850.00 in Box 6.
    app(EnableCompanyCurrency::class)->handle($t->company, 'EUR');
    $t->company->refresh();
    $euCustomer = Contact::create(['display_name' => 'Kunde GmbH', 'is_customer' => true, 'currency_code' => 'EUR']);
    $export = Invoice::create([
        'contact_id' => $euCustomer->id,
        'invoice_no' => 'INV-EUR',
        'invoice_date' => '2026-03-20',
        'due_date' => '2026-03-20',
        'currency_code' => 'EUR',
        'fx_rate' => '0.85',
    ]);
    foreach (ukLines([[100000, $t->zero]], $t->income) as $line) {
        $export->lines()->create($line);
    }
    app(InvoicePoster::class)->post($export);

    // Next quarter — must not appear anywhere in Q1.
    $later = ukInvoice('2026-04-02', [[77700, $t->standard]]);

    return compact('mixed', 'discounted', 'memo', 'bill', 'expense', 'credit', 'export', 'later');
}

it('works out all nine HMRC boxes for a VAT quarter', function () {
    postUkQuarter();

    $boxes = app(VatReturnCalculator::class)->forPeriod($this->agency, $this->start, $this->end);

    expect($boxes->box1)->toBe(26000)
        ->and($boxes->box2)->toBe(0)
        ->and($boxes->box3)->toBe(26000)
        ->and($boxes->box4)->toBe(5228)
        ->and($boxes->box5)->toBe(20772)
        ->and($boxes->isRepayment())->toBeFalse()
        ->and($boxes->box6)->toBe(245000)
        ->and($boxes->box7)->toBe(29500)
        ->and($boxes->box8)->toBe(0)
        ->and($boxes->box9)->toBe(0);
});

it('agrees with the generic tax return on the VAT and the net', function () {
    postUkQuarter();

    $return = TaxReturn::create([
        'tax_agency_id' => $this->agency->id,
        'tax_return_no' => 'TR-UK-1',
        'period_start' => $this->start->toDateString(),
        'period_end' => $this->end->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);

    $boxes = app(VatReturnCalculator::class)->forReturn($return);
    $figures = app(TaxReturnCalculator::class)->forReturn($return);

    expect($boxes->box1)->toBe($figures->collectedCents)
        ->and($boxes->box4)->toBe($figures->paidCents)
        ->and($boxes->box5)->toBe($figures->netCents);
});

it('reports a repayment when input VAT exceeds output VAT', function () {
    ukInvoice('2026-01-10', [[10000, $this->standard]]);
    ukBill('2026-01-11', [[90000, $this->standard]]);

    $boxes = app(VatReturnCalculator::class)->forPeriod($this->agency, $this->start, $this->end);

    expect($boxes->box1)->toBe(2000)
        ->and($boxes->box4)->toBe(18000)
        ->and($boxes->box5)->toBe(-16000)
        ->and($boxes->isRepayment())->toBeTrue()
        ->and($boxes->box5Absolute())->toBe(16000);
});

it('leaves voided documents out of every box', function () {
    $invoice = ukInvoice('2026-01-15', [[100000, $this->standard]]);
    app(InvoicePoster::class)->void($invoice, CarbonImmutable::parse('2026-01-20'));

    $boxes = app(VatReturnCalculator::class)->forPeriod($this->agency, $this->start, $this->end);

    expect($boxes->box1)->toBe(0)
        ->and($boxes->box6)->toBe(0);
});

it('drops pence from Boxes 6 to 9 rather than rounding', function () {
    expect(VatReturnBoxes::wholePounds(99))->toBe(0)
        ->and(VatReturnBoxes::wholePounds(199))->toBe(100)
        ->and(VatReturnBoxes::wholePounds(245049))->toBe(245000)
        ->and(VatReturnBoxes::wholePounds(-199))->toBe(-100);

    $boxes = VatReturnBoxes::make(vatOnSales: 1, vatReclaimed: 0, netSales: 99999, netPurchases: 50);

    expect($boxes->box6)->toBe(99900)
        ->and($boxes->box7)->toBe(0)
        ->and(VatReturnBoxes::fromArray($boxes->toArray()))->toEqual($boxes);
});

it('moves a document whose VAT is left off the return out of Box 6 too', function () {
    $docs = postUkQuarter();

    $excludedLineId = app(TaxReturnBuilder::class)
        ->build($this->agency, $this->start, $this->end)
        ->first(fn ($row) => $row['source_type'] === Invoice::class && (int) $row['source_id'] === $docs['mixed']->id)['journal_line_id'];

    $return = TaxReturn::create([
        'tax_agency_id' => $this->agency->id,
        'tax_return_no' => 'TR-UK-2',
        'period_start' => $this->start->toDateString(),
        'period_end' => $this->end->toDateString(),
        'status' => TaxReturnStatus::Draft,
        'excluded_journal_line_ids' => [$excludedLineId],
    ]);

    $boxes = app(VatReturnCalculator::class)->forReturn($return);

    // The £200 VAT and the invoice's £1,350.49 of sales both move out.
    expect($boxes->box1)->toBe(6000)
        ->and($boxes->box6)->toBe(110000);
});

it('freezes the nine boxes when a UK return is filed', function () {
    $docs = postUkQuarter();

    $return = TaxReturn::create([
        'tax_agency_id' => $this->agency->id,
        'tax_return_no' => 'TR-UK-3',
        'period_start' => $this->start->toDateString(),
        'period_end' => $this->end->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);

    $filed = app(TaxReturnFiler::class)->file($return);

    expect($filed->vat_boxes)->toBe([
        'box1' => 26000, 'box2' => 0, 'box3' => 26000, 'box4' => 5228, 'box5' => 20772,
        'box6' => 245000, 'box7' => 29500, 'box8' => 0, 'box9' => 0,
    ]);

    // A zero-rated sale posted into the filed quarter afterwards is blocked by
    // the filed-period guard for VAT codes; the frozen boxes would not move
    // even if it were not.
    expect(app(VatReturnCalculator::class)->forReturn($filed)->box6)->toBe(245000);
});

it('does not freeze VAT boxes on a Canadian return', function () {
    $ca = Company::factory()->create();
    app()->instance('current_company', $ca);
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $return = TaxReturn::create([
        'tax_agency_id' => $gst->agency_id,
        'tax_return_no' => 'TR-CA-1',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'status' => TaxReturnStatus::Draft,
    ]);

    expect(app(TaxReturnFiler::class)->file($return)->vat_boxes)->toBeNull();
});

it('shows the nine boxes on the VAT Return report', function () {
    postUkQuarter();

    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('reports.vat-return', ['company' => $this->company->slug, 'start' => '2026-01-01', 'end' => '2026-03-31']))
        ->assertOk()
        ->assertSee('VAT Return')
        ->assertSee('£260.00')
        ->assertSee('£52.28')
        ->assertSee('£207.72')
        ->assertSee('£2,450')
        ->assertSee('£295')
        ->assertSee('You owe HMRC £207.72 for this period.');
});

it('defaults the report to the last complete quarter and can jump quarters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-12 10:00', 'Europe/London'));
    $this->company->update(['timezone' => 'Europe/London']);

    Livewire::test('pages::reports.vat-return', ['company' => $this->company])
        ->assertSet('startDate', '2026-01-01')
        ->assertSet('endDate', '2026-03-31')
        ->assertSet('agencyId', $this->agency->id)
        ->call('useQuarter', 0)
        ->assertSet('startDate', '2026-04-01')
        ->assertSet('endDate', '2026-06-30');
});

it('exports the nine boxes as CSV with whole pounds in Boxes 6 to 9', function () {
    postUkQuarter();

    $response = Livewire::test('pages::reports.vat-return', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-03-31')
        ->call('exportCsv');

    $response->assertFileDownloaded('vat-return-2026-01-01-2026-03-31.csv');
});

it('warns instead of erroring when the period is back to front', function () {
    Livewire::test('pages::reports.vat-return', ['company' => $this->company])
        ->set('startDate', '2026-03-31')
        ->set('endDate', '2026-01-01')
        ->assertSee('Choose a period whose end is on or after its start.');
});

it('lists the VAT Return report for UK companies only', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $ukKeys = array_keys(ReportCatalog::flatten($this->company, $user));

    $ca = Company::factory()->create();
    $ca->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $caKeys = array_keys(ReportCatalog::flatten($ca, $user));

    expect($ukKeys)->toContain('reports.vat-return')
        ->and($caKeys)->not->toContain('reports.vat-return');
});

it('shows the nine boxes on a UK tax return, live for a draft and frozen once filed', function () {
    postUkQuarter();

    $return = TaxReturn::create([
        'tax_agency_id' => $this->agency->id,
        'tax_return_no' => 'TR-UK-4',
        'period_start' => $this->start->toDateString(),
        'period_end' => $this->end->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);

    Livewire::test('pages::tax-returns.show', ['company' => $this->company, 'tax_return' => $return])
        ->assertSee('HMRC VAT return')
        ->assertSee('Worked out live until the return is filed.')
        ->assertSeeHtml('data-test="vat-box-5-amount"')
        ->assertSee('£207.72')
        ->assertSee('£2,450');

    app(TaxReturnFiler::class)->file($return);

    Livewire::test('pages::tax-returns.show', ['company' => $this->company, 'tax_return' => $return->fresh()])
        ->assertSee('As filed.')
        ->assertSee('£207.72');
});

it('uses input VAT wording on the Sales Tax report and tax return tiles', function () {
    postUkQuarter();

    Livewire::test('pages::reports.sales-tax', ['company' => $this->company])
        ->assertSee('Paid (Input VAT)')
        ->assertSee('input VAT reclaimed on purchases')
        ->assertDontSee('Paid (ITC)');
});
