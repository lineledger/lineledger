<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Classification;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1, 'features_classes' => true]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postIncome(string $date, int $cents, ?int $classId = null): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 0, 'class_id' => $classId]);
    $entry->lines()->create(['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 1, 'class_id' => $classId]);
}

it('computes actual, budget, and variance per account', function () {
    postIncome('2026-02-15', 80000); // actual income 800.00

    $budget = Budget::create(['name' => 'B', 'fiscal_year' => 2026]);
    $budget->lines()->create(['account_id' => $this->income->id, 'month_2_cents' => 100000]); // budget 1000.00 in Feb

    $report = Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->instance()
        ->report();

    $row = collect($report['groups']['income']['rows'])->firstWhere('code', $this->income->code);

    expect($row['actual'])->toBe(80000)
        ->and($row['budget'])->toBe(100000)
        ->and($row['variance'])->toBe(-20000)
        ->and($row['favorable'])->toBeFalse(); // income under budget is unfavourable
});

it('respects a non-January fiscal year when mapping budget months', function () {
    $company = Company::factory()->create(['fiscal_year_start_month' => 7]);
    app()->instance('current_company', $company);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    // Fiscal year 2026 starts July 2026. month_1 = July 2026.
    $budget = Budget::create(['name' => 'B', 'fiscal_year' => 2026]);
    $budget->lines()->create(['account_id' => $income->id, 'month_1_cents' => 50000]);

    expect($budget->monthStart(1)->toDateString())->toBe('2026-07-01');

    $byAccount = $budget->budgetedCentsByAccount(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($byAccount[$income->id])->toBe(50000);
});

it('filters actuals by a class-scoped budget', function () {
    $classA = Classification::create(['name' => 'Dept A', 'is_active' => true]);
    $classB = Classification::create(['name' => 'Dept B', 'is_active' => true]);

    postIncome('2026-03-01', 30000, $classA->id);
    postIncome('2026-03-01', 90000, $classB->id);

    $budget = Budget::create(['name' => 'A only', 'fiscal_year' => 2026, 'class_id' => $classA->id]);
    $budget->lines()->create(['account_id' => $this->income->id, 'month_3_cents' => 25000]);

    $report = Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->instance()
        ->report();

    $row = collect($report['groups']['income']['rows'])->firstWhere('code', $this->income->code);

    // Only Dept A's 300.00 is counted, not Dept B's 900.00.
    expect($row['actual'])->toBe(30000)
        ->and($row['budget'])->toBe(25000);
});

it('renders the page', function () {
    $budget = Budget::create(['name' => 'B', 'fiscal_year' => 2026]);
    $budget->lines()->create(['account_id' => $this->income->id, 'month_1_cents' => 1000]);

    Livewire::test('pages::reports.budget-vs-actual', ['company' => $this->company])
        ->set('budgetId', $budget->id)
        ->assertOk();
});
