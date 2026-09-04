<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Insights\Detectors\CashflowRunwayDetector;
use App\Services\Insights\Detectors\CashflowShortfallDetector;
use App\Services\Reporting\CashflowForecaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function fcAccount(Company $company, AccountSubtype $subtype): Account
{
    return Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('subtype', $subtype->value)
        ->orderBy('code')
        ->firstOrFail();
}

/**
 * @param  array<int, array{account: Account, debit?: int, credit?: int}>  $lines
 */
function fcPost(Company $company, string $date, array $lines): void
{
    app()->instance('current_company', $company);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.fake()->unique()->numerify('######'),
        'entry_date' => CarbonImmutable::parse($date),
        'memo' => 'Forecast test',
        'is_posted' => true,
    ]);

    foreach ($lines as $i => $line) {
        $entry->lines()->create([
            'account_id' => $line['account']->id,
            'debit_cents' => $line['debit'] ?? 0,
            'credit_cents' => $line['credit'] ?? 0,
            'line_order' => $i,
        ]);
    }
}

function fcInvoice(Company $company, int $totalCents, string $due, InvoiceStatus $status = InvoiceStatus::Posted): Invoice
{
    app()->instance('current_company', $company);

    return Invoice::create([
        'contact_id' => Contact::factory()->customer()->create()->id,
        'invoice_no' => 'INV-'.fake()->unique()->numerify('######'),
        'invoice_date' => $due,
        'due_date' => $due,
        'status' => $status,
        'total_cents' => $totalCents,
    ]);
}

function fcBill(Company $company, int $totalCents, string $due, BillStatus $status = BillStatus::Posted): Bill
{
    app()->instance('current_company', $company);

    return Bill::create([
        'contact_id' => Contact::factory()->vendor()->create()->id,
        'bill_no' => 'BILL-'.fake()->unique()->numerify('######'),
        'bill_date' => $due,
        'due_date' => $due,
        'status' => $status,
        'total_cents' => $totalCents,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::create(2026, 6, 15));

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    // $5,000 opening cash, deposited two weeks ago.
    fcPost($this->company, '2026-06-01', [
        ['account' => fcAccount($this->company, AccountSubtype::Bank), 'debit' => 500000],
        ['account' => fcAccount($this->company, AccountSubtype::Equity), 'credit' => 500000],
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    Carbon::setTestNow();
});

it('projects opening cash and buckets AR/AP by due date', function () {
    fcInvoice($this->company, 200000, '2026-07-01'); // 16 days out → week index 2
    fcBill($this->company, 100000, '2026-06-10');    // overdue → period 0

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    expect($forecast['opening_cents'])->toBe(500000)
        ->and($forecast['periods'][0]['scheduled_out_cents'])->toBe(100000)
        ->and($forecast['periods'][2]['scheduled_in_cents'])->toBe(200000)
        ->and($forecast['periods'][0]['committed_closing_cents'])->toBe(400000)
        ->and($forecast['periods'][2]['committed_closing_cents'])->toBe(600000)
        ->and($forecast['lowest_committed_cents'])->toBe(400000)
        ->and($forecast['breaches_floor'])->toBeFalse();
});

it('flags a below-floor dip when the floor is above the trough', function () {
    fcBill($this->company, 100000, '2026-06-10');

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 450000);

    expect($forecast['breaches_floor'])->toBeTrue()
        ->and($forecast['first_breach_index'])->toBe(0)
        ->and($forecast['periods'][0]['below_floor'])->toBeTrue();
});

it('drops invoices due beyond the horizon', function () {
    fcInvoice($this->company, 999999, '2026-12-31'); // far past a 13-week horizon

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    $scheduledIn = array_sum(array_map(fn (array $p): int => $p['scheduled_in_cents'], $forecast['periods']));

    expect($scheduledIn)->toBe(0);
});

it('runway detector fires (urgent) when committed cash goes negative', function () {
    fcBill($this->company, 800000, '2026-06-10'); // 500000 − 800000 = −300000

    $candidates = app(CashflowRunwayDetector::class)->detect($this->company, CarbonImmutable::create(2026, 6, 15));

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->key)->toBe('cashflow-runway')
        ->and($candidates[0]->urgent)->toBeTrue()
        ->and($candidates[0]->facts['lowest_display'])->toBe('-$3,000');
});

it('shortfall detector fires on a material dip that stays positive, and yields to runway', function () {
    fcBill($this->company, 300000, '2026-06-10'); // 500000 − 300000 = 200000; drop 60%

    $runway = app(CashflowRunwayDetector::class)->detect($this->company, CarbonImmutable::create(2026, 6, 15));
    $shortfall = app(CashflowShortfallDetector::class)->detect($this->company, CarbonImmutable::create(2026, 6, 15));

    expect($runway)->toBe([])
        ->and($shortfall)->toHaveCount(1)
        ->and($shortfall[0]->key)->toBe('cashflow-shortfall')
        ->and($shortfall[0]->facts['pct_drop'])->toBe(60);
});

it('both detectors stay silent on healthy books', function () {
    fcBill($this->company, 50000, '2026-06-10'); // 10% dip — immaterial

    $today = CarbonImmutable::create(2026, 6, 15);

    expect(app(CashflowRunwayDetector::class)->detect($this->company, $today))->toBe([])
        ->and(app(CashflowShortfallDetector::class)->detect($this->company, $today))->toBe([]);
});

it('renders the forecast report page for a company member', function () {
    fcInvoice($this->company, 200000, '2026-07-01');
    fcBill($this->company, 100000, '2026-06-10');

    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('reports.cash-flow-forecast', $this->company))
        ->assertOk()
        ->assertSee('Cash flow forecast')
        ->assertSee('Wk of');
});

it('projects post-dated cash entries on their booked date instead of losing them', function () {
    // A post-dated cheque and a future-dated bank charge: in the GL, but not in "cash today".
    fcPost($this->company, '2026-06-25', [
        ['account' => fcAccount($this->company, AccountSubtype::Expense), 'debit' => 120000],
        ['account' => fcAccount($this->company, AccountSubtype::Bank), 'credit' => 120000],
    ]);
    fcPost($this->company, '2026-06-30', [
        ['account' => fcAccount($this->company, AccountSubtype::Expense), 'debit' => 600],
        ['account' => fcAccount($this->company, AccountSubtype::Bank), 'credit' => 600],
    ]);

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    // Jun 25 → week index 1 (Jun 22–28); Jun 30 → week index 2 (Jun 29–Jul 5).
    expect($forecast['opening_cents'])->toBe(500000)
        ->and($forecast['periods'][1]['scheduled_out_cents'])->toBe(120000)
        ->and($forecast['periods'][1]['items_out'][0]['kind'])->toBe('scheduled')
        ->and($forecast['periods'][1]['items_out'][0]['label'])->toBe('Forecast test')
        ->and($forecast['periods'][2]['scheduled_out_cents'])->toBe(600)
        ->and($forecast['periods'][2]['committed_closing_cents'])->toBe(379400);
});

it('explains each period with the documents behind it', function () {
    $invoice = fcInvoice($this->company, 200000, '2026-07-01');
    $bill = fcBill($this->company, 100000, '2026-06-10');

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    $in = $forecast['periods'][2]['items_in'];
    $out = $forecast['periods'][0]['items_out'];

    expect($in)->toHaveCount(1)
        ->and($in[0]['label'])->toContain($invoice->invoice_no)
        ->and($in[0]['amount_cents'])->toBe(200000)
        ->and($in[0]['detail'])->toContain('due Jul 1')
        ->and($out)->toHaveCount(1)
        ->and($out[0]['label'])->toContain($bill->bill_no)
        ->and($out[0]['detail'])->toContain('days overdue')
        ->and($out[0]['detail'])->toContain('counted now');
});

it('leaves receivables overdue past the cut-off out of the committed track and lists them as doubtful', function () {
    fcInvoice($this->company, 150000, '2026-02-01'); // 134 days overdue
    fcInvoice($this->company, 50000, '2026-06-01');  // 14 days overdue → collected now

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    expect($forecast['periods'][0]['scheduled_in_cents'])->toBe(50000)
        ->and($forecast['doubtful_receivables_cents'])->toBe(150000)
        ->and($forecast['doubtful_receivables'])->toHaveCount(1)
        ->and($forecast['doubtful_after_days'])->toBe(90);

    // A wider cut-off counts it again.
    $lenient = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0, null, 365);

    expect($lenient['periods'][0]['scheduled_in_cents'])->toBe(200000)
        ->and($lenient['doubtful_receivables'])->toBe([]);
});

it('pushes expected collections out by how late customers typically pay', function () {
    // Three invoices paid 20, 30 and 40 days after their due date → median 30.
    foreach ([[20, '2026-03-01'], [30, '2026-03-05'], [40, '2026-03-10']] as [$lag, $due]) {
        $paid = fcInvoice($this->company, 10000, $due, InvoiceStatus::Paid);
        $paid->forceFill(['amount_paid_cents' => 10000])->save();

        $receipt = CustomerReceipt::create([
            'contact_id' => $paid->contact_id,
            'receipt_no' => 'RCPT-'.fake()->unique()->numerify('######'),
            'receipt_date' => CarbonImmutable::parse($due)->addDays($lag)->toDateString(),
            'deposit_to_account_id' => fcAccount($this->company, AccountSubtype::Bank)->id,
            'amount_cents' => 10000,
            'status' => ReceiptStatus::Posted,
        ]);
        $receipt->applications()->create(['invoice_id' => $paid->id, 'amount_cents' => 10000]);
    }

    fcInvoice($this->company, 200000, '2026-06-20'); // due in 5 days → expected Jul 20 → week index 5

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    expect($forecast['collection_delay_days'])->toBe(30)
        ->and($forecast['periods'][0]['scheduled_in_cents'])->toBe(0)
        ->and($forecast['periods'][5]['scheduled_in_cents'])->toBe(200000)
        ->and($forecast['periods'][5]['items_in'][0]['detail'])->toContain('expected Jul 20');
});

it('ties book cash to the bank: cleared balance, uncleared cheques, deposits in transit', function () {
    $bank = fcAccount($this->company, AccountSubtype::Bank);

    // Mark the opening deposit cleared, then write a cheque that has not cleared.
    JournalLine::query()->where('account_id', $bank->id)->update(['cleared_at' => now()]);
    fcPost($this->company, '2026-06-10', [
        ['account' => fcAccount($this->company, AccountSubtype::Expense), 'debit' => 360268],
        ['account' => $bank, 'credit' => 360268],
    ]);

    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);
    $position = $forecast['cash_position'];

    expect($forecast['opening_cents'])->toBe(139732)
        ->and($position['tracked'])->toBeTrue()
        ->and($position['cleared_cents'])->toBe(500000)
        ->and($position['outstanding_payments_cents'])->toBe(360268)
        ->and($position['outstanding_payments_count'])->toBe(1)
        ->and($position['deposits_in_transit_cents'])->toBe(0)
        ->and($position['other_cash_cents'])->toBe(0);
});

it('reports the cash position as untracked when no bank line has ever been cleared', function () {
    $forecast = app(CashflowForecaster::class)->forecast($this->company, 'week', 13, 0);

    expect($forecast['cash_position']['tracked'])->toBeFalse()
        ->and($forecast['cash_position']['other_cash_cents'])->toBe(500000);
});

it('renders the rationale, cash position and doubtful cut-off on the report page', function () {
    $bank = fcAccount($this->company, AccountSubtype::Bank);
    JournalLine::query()->where('account_id', $bank->id)->update(['cleared_at' => now()]);
    fcInvoice($this->company, 200000, '2026-07-01');
    fcInvoice($this->company, 150000, '2026-02-01');
    fcBill($this->company, 100000, '2026-06-10');

    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    Livewire::actingAs($user)
        ->test('pages::reports.cash-flow-forecast', ['company' => $this->company])
        ->assertSeeHtml('data-test="cash-position"')
        ->assertSeeHtml('data-test="expected-in"')
        ->assertSeeHtml('data-test="expected-out"')
        ->assertSeeHtml('data-test="forecast-items"')
        ->assertSeeHtml('data-test="doubtful-receivables"')
        ->assertSee('Cleared at bank')
        ->set('doubtfulDays', '365')
        ->assertDontSeeHtml('data-test="doubtful-receivables"');
});
