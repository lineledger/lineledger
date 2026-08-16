<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MemorizedReport;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Reporting\CashBasisCalculator;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    // Two stable periods well in the past so "today" never interferes.
    $this->p1Start = CarbonImmutable::parse('2026-03-01');
    $this->p1End = CarbonImmutable::parse('2026-03-31');
    $this->p2Start = CarbonImmutable::parse('2026-04-01');
    $this->p2End = CarbonImmutable::parse('2026-04-30');

    $this->customer = Contact::create(['display_name' => 'Cash Basis Customer', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Cash Basis Vendor', 'is_vendor' => true]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function cashBasisMap(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
{
    return app(CashBasisCalculator::class)->periodChangesByAccount($company, $start, $end);
}

/**
 * @param  array<int, array{0: int, 1: int}>  $lines  [account_id, subtotal_cents][]
 */
function cashBasisInvoice(Contact $customer, CarbonImmutable $date, array $lines, int $taxCents = 0): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date->addDays(30),
    ]);

    foreach ($lines as $i => [$accountId, $cents]) {
        $invoice->lines()->create([
            'account_id' => $accountId,
            'description' => 'line '.$i,
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => $i === 0 ? $taxCents : 0,
            'line_total_cents' => $cents + ($i === 0 ? $taxCents : 0),
            'line_order' => $i,
        ]);
    }

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

function cashBasisReceipt(Contact $customer, Invoice $invoice, CarbonImmutable $date, int $cents): CustomerReceipt
{
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'RCPT-'.uniqid(),
        'receipt_date' => $date,
        'amount_cents' => $cents,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => $cents]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    return $receipt->fresh();
}

it('recognizes invoice income when the receipt is applied, not when invoiced', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = cashBasisInvoice($this->customer, $this->p1Start->addDays(5), [[$income->id, 100000]]);
    cashBasisReceipt($this->customer, $invoice, $this->p2Start->addDays(5), 100000);

    $calc = app(ReportCalculator::class);

    // Accrual: income in P1, nothing in P2.
    expect($calc->periodChange($income, $this->p1Start, $this->p1End))->toBe(100000)
        ->and($calc->periodChange($income, $this->p2Start, $this->p2End))->toBe(0);

    // Cash: nothing in P1, income in P2.
    $p1 = cashBasisMap($this->company, $this->p1Start, $this->p1End);
    $p2 = cashBasisMap($this->company, $this->p2Start, $this->p2End);

    expect($p1[$income->id] ?? 0)->toBe(0)
        ->and($p2[$income->id] ?? 0)->toBe(100000);
});

it('allocates a partial payment pro-rata across lines and excludes tax', function () {
    $incomeA = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $incomeB = Account::create([
        'code' => '4999',
        'name' => 'Second Income',
        'type' => AccountType::Income->value,
        'subtype' => AccountSubtype::Income->value,
        'normal_balance' => 'credit',
    ]);

    // 600 + 400 subtotal, 130 tax → total 1130. Pay exactly half: 565.
    $invoice = cashBasisInvoice(
        $this->customer,
        $this->p1Start->addDays(3),
        [[$incomeA->id, 60000], [$incomeB->id, 40000]],
        taxCents: 13000,
    );

    expect($invoice->total_cents)->toBe(113000);

    cashBasisReceipt($this->customer, $invoice, $this->p2Start->addDays(3), 56500);

    $p2 = cashBasisMap($this->company, $this->p2Start, $this->p2End);

    expect($p2[$incomeA->id] ?? 0)->toBe(30000)
        ->and($p2[$incomeB->id] ?? 0)->toBe(20000);

    // Tax never reaches the P&L: total recognized is exactly half the subtotal.
    expect(array_sum($p2))->toBe(50000);
});

it('treats direct journal activity identically under both bases', function () {
    $expense = Account::query()->where('type', AccountType::Expense->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $entry = JournalEntry::create([
        'entry_no' => 'JE-CASH',
        'entry_date' => $this->p1Start->addDays(10),
        'memo' => 'direct expense',
    ]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => 12345, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 12345, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $accrual = app(ReportCalculator::class)->periodChange($expense, $this->p1Start, $this->p1End);
    $cash = cashBasisMap($this->company, $this->p1Start, $this->p1End);

    expect($accrual)->toBe(12345)
        ->and($cash[$expense->id] ?? 0)->toBe(12345);
});

it('recognizes bill expense when the payment is applied', function () {
    $expense = Account::query()->where('type', AccountType::Expense->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => 'vendor',
        'bill_no' => 'BILL-CASH',
        'bill_date' => $this->p1Start->addDays(4),
        'due_date' => $this->p1Start->addDays(34),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'services',
        'quantity' => '1',
        'unit_price_cents' => 80000,
        'line_subtotal_cents' => 80000,
        'line_tax_cents' => 0,
        'line_total_cents' => 80000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $payment = BillPayment::create([
        'contact_id' => $this->vendor->id,
        'payment_no' => 'PAY-CASH',
        'payment_date' => $this->p2Start->addDays(4),
        'amount_cents' => 80000,
        'paid_from_account_id' => $bank->id,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 80000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $p1 = cashBasisMap($this->company, $this->p1Start, $this->p1End);
    $p2 = cashBasisMap($this->company, $this->p2Start, $this->p2End);

    expect($p1[$expense->id] ?? 0)->toBe(0)
        ->and($p2[$expense->id] ?? 0)->toBe(80000);
});

it('drops income from the cash basis when the receipt is voided', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = cashBasisInvoice($this->customer, $this->p1Start->addDays(5), [[$income->id, 50000]]);
    $receipt = cashBasisReceipt($this->customer, $invoice, $this->p2Start->addDays(5), 50000);

    app(ReceiptPoster::class)->void($receipt, $this->p2Start->addDays(6));

    $p2 = cashBasisMap($this->company, $this->p2Start, $this->p2End);

    expect($p2[$income->id] ?? 0)->toBe(0);
});

it('converts a foreign invoice slice at the invoice locked rate', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // Built directly (no posting stack): step 2 only reads the application,
    // the posted receipt's date, and the invoice's lines/total/rate.
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-FX',
        'invoice_date' => $this->p1Start->addDays(2),
        'due_date' => $this->p1Start->addDays(32),
        'currency_code' => 'USD',
        'fx_rate' => '1.35',
        'status' => 'posted',
        'subtotal_cents' => 100000,
        'total_cents' => 100000,
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'usd services',
        'quantity' => '1',
        'unit_price_cents' => 100000,
        'line_subtotal_cents' => 100000,
        'line_tax_cents' => 0,
        'line_total_cents' => 100000,
        'line_order' => 0,
    ]);

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'RCPT-FX',
        'receipt_date' => $this->p2Start->addDays(2),
        'amount_cents' => 100000,
        'currency_code' => 'USD',
        'fx_rate' => '1.40',
        'status' => 'posted',
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 100000]);

    $p2 = cashBasisMap($this->company, $this->p2Start, $this->p2End);

    // USD 1,000.00 at the invoice's locked 1.35 → CAD 1,350.00; the
    // receipt-vs-invoice rate difference is a realized FX journal line and
    // belongs to step 1, not the recognition slice.
    expect($p2[$income->id] ?? 0)->toBe(135000);
});

it('keeps a voided invoice out of the cash basis entirely', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = cashBasisInvoice($this->customer, $this->p1Start->addDays(5), [[$income->id, 70000]]);
    app(InvoicePoster::class)->void($invoice, $this->p1Start->addDays(8));

    // Neither the invoice entry nor its reversal may leak into cash income.
    $p1 = cashBasisMap($this->company, $this->p1Start, $this->p1End);

    expect($p1[$income->id] ?? 0)->toBe(0);
});

it('toggles the income statement page between bases', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = cashBasisInvoice($this->customer, $this->p1Start->addDays(5), [[$income->id, 100000]]);
    cashBasisReceipt($this->customer, $invoice, $this->p2Start->addDays(5), 100000);

    $page = fn (string $basis, CarbonImmutable $start, CarbonImmutable $end) => Livewire\Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'custom')
        ->set('startDate', $start->toDateString())
        ->set('endDate', $end->toDateString())
        ->set('reportBasis', $basis);

    // Accrual P1 shows the income; cash P1 does not.
    expect($page('accrual', $this->p1Start, $this->p1End)->instance()->report['total_income'])->toBe(100000)
        ->and($page('cash', $this->p1Start, $this->p1End)->instance()->report['total_income'])->toBe(0)
        ->and($page('cash', $this->p2Start, $this->p2End)->instance()->report['total_income'])->toBe(100000);

    // The basis is visible in the subtitle and exports carry a basis-suffixed name.
    $component = $page('cash', $this->p2Start, $this->p2End);
    $component->assertSee(__('Cash basis'));
    $component->call('exportPdf');
    expect(data_get($component->effects, 'download.name'))->toEndWith('-cash.pdf');
});

it('uses cash figures for both comparison columns', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // P1: invoice + immediate payment (cash income in P1).
    $first = cashBasisInvoice($this->customer, $this->p1Start->addDays(2), [[$income->id, 40000]]);
    cashBasisReceipt($this->customer, $first, $this->p1Start->addDays(3), 40000);

    // P2: invoice issued AND paid (cash income in P2).
    $second = cashBasisInvoice($this->customer, $this->p2Start->addDays(2), [[$income->id, 90000]]);
    cashBasisReceipt($this->customer, $second, $this->p2Start->addDays(3), 90000);

    $component = Livewire\Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'custom')
        ->set('startDate', $this->p2Start->toDateString())
        ->set('endDate', $this->p2End->toDateString())
        ->set('comparisonBasis', 'prior_period')
        ->set('reportBasis', 'cash');

    $report = $component->instance()->report;

    expect($report['total_income'])->toBe(90000)
        ->and($report['prior_total_income'])->toBe(40000);
});

it('memorizes and restores the cash basis, and rejects invalid values', function () {
    Livewire\Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('reportBasis', 'cash')
        ->set('memorizeName', 'Cash P&L')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $this->user->id)->first();
    expect($memorized->settings['reportBasis'])->toBe('cash');

    Livewire\Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('reportBasis', 'cash');

    Livewire\Livewire::withQueryParams(['basis' => 'bogus'])
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->assertSet('reportBasis', 'accrual');
});
