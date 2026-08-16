<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Insights\Detectors\CashflowRunwayDetector;
use App\Services\Insights\Detectors\CashflowShortfallDetector;
use App\Services\Reporting\CashflowForecaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

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
