<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('type', AccountType::Asset->value)->orderBy('code')->first();
    $this->other = Account::query()->where('type', AccountType::Liability->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function balanceSheetEntry(string $date, int $cents): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => test()->other->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('populates the prior column with the cumulative balance one year earlier', function () {
    // Balances are cumulative as-of a date. With as-of 2026-05-22 the prior column
    // is the balance as of 2025-05-22. The 2024-09-01 entry predates both dates
    // (counts in current AND prior); the 2025-09-01 entry lands only in current.
    balanceSheetEntry('2024-09-01', 50000);
    balanceSheetEntry('2025-09-01', 100000);

    $report = Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-22')
        ->set('comparisonBasis', 'prior_year')
        ->instance()
        ->report();

    expect($report['total_assets'])->toBe(150000)
        ->and($report['prior_total_assets'])->toBe(50000);
});

it('leaves the prior totals at zero when comparison is off', function () {
    balanceSheetEntry('2024-09-01', 50000);

    $report = Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-22')
        ->set('comparisonBasis', 'off')
        ->instance()
        ->report();

    expect($report['total_assets'])->toBe(50000)
        ->and($report['prior_total_assets'])->toBe(0);
});

it('shows the prior and % change columns when comparing', function () {
    balanceSheetEntry('2024-09-01', 50000);  // prior → $500
    balanceSheetEntry('2025-09-01', 50000);  // current adds another $500 → $1,000

    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-22')
        ->set('comparisonBasis', 'prior_year')
        ->assertOk()
        ->assertSee('Prior')
        ->assertSee('100.0%'); // ($1,000 - $500) / $500
});
