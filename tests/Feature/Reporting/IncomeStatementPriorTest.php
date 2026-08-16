<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function incomeEntry(string $date, int $cents): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('populates the prior column with the same range one year earlier', function () {
    // Current range [2025-08-01 .. 2026-05-22]; prior is exactly one year back
    // [2024-08-01 .. 2025-05-22]. 2024-09-01 lands in the prior-year window (and
    // would NOT under a same-length-preceding-period scheme — proving year alignment).
    incomeEntry('2024-09-01', 50000);  // prior year
    incomeEntry('2025-09-01', 100000); // current year

    $report = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2025-08-01')
        ->set('endDate', '2026-05-22')
        ->set('comparisonBasis', 'prior_year')
        ->instance()
        ->report();

    expect($report['total_income'])->toBe(100000)
        ->and($report['prior_total_income'])->toBe(50000);
});

it('compares to the immediately preceding month when basis is prior period', function () {
    // Freeze the clock so the "This Month" preset deterministically resolves to
    // May 2026. With the prior-period basis, May's comparison column pulls April
    // (NOT May of last year), so both months show their own totals.
    $this->travelTo(CarbonImmutable::parse('2026-05-15'));

    incomeEntry('2026-04-15', 30000); // prior period (April)
    incomeEntry('2026-05-10', 80000); // current (May)

    $report = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'this_month')
        ->set('comparisonBasis', 'prior_period')
        ->instance()
        ->report();

    expect($report['total_income'])->toBe(80000)
        ->and($report['prior_total_income'])->toBe(30000);
});

it('shows change and % change columns when comparing to prior', function () {
    incomeEntry('2024-09-01', 50000);  // prior year  → $500
    incomeEntry('2025-09-01', 100000); // current year → $1,000

    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2025-08-01')
        ->set('endDate', '2026-05-22')
        ->set('comparisonBasis', 'prior_year')
        ->assertOk()
        ->assertSee('% Change')
        ->assertSee('100.0%'); // ($1,000 - $500) / $500
});

it('applies the This Fiscal Year-to-date preset to the date inputs', function () {
    // Company fiscal year starts in August.
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'this_fiscal_year')
        ->assertSet('startDate', CarbonImmutable::now()->month >= 8
            ? CarbonImmutable::create(CarbonImmutable::now()->year, 8, 1)->toDateString()
            : CarbonImmutable::create(CarbonImmutable::now()->year - 1, 8, 1)->toDateString())
        ->set('startDate', '2020-01-01')
        ->assertSet('preset', 'custom'); // editing a date reverts to custom
});
