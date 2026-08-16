<?php

use App\Actions\Budgeting\SaveBudget;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a budget with twelve-month lines in cents', function () {
    $budget = app(SaveBudget::class)->handle([
        'name' => 'FY2026 Operating',
        'fiscal_year' => 2026,
        'lines' => [
            ['account_id' => $this->income->id, 'month_1_cents' => 100000, 'month_2_cents' => 120000],
        ],
    ]);

    expect($budget->name)->toBe('FY2026 Operating')
        ->and($budget->fiscal_year)->toBe(2026)
        ->and($budget->lines)->toHaveCount(1);

    $line = $budget->lines->first();

    expect($line->month_1_cents)->toBe(100000)
        ->and($line->month_2_cents)->toBe(120000)
        ->and($line->month_12_cents)->toBe(0)
        ->and($line->company_id)->toBe($this->company->id);
});

it('re-syncs lines on update', function () {
    $budget = app(SaveBudget::class)->handle([
        'name' => 'Budget', 'fiscal_year' => 2026,
        'lines' => [['account_id' => $this->income->id, 'month_1_cents' => 5000]],
    ]);

    $budget = app(SaveBudget::class)->handle([
        'name' => 'Budget', 'fiscal_year' => 2026,
        'lines' => [['account_id' => $this->expense->id, 'month_1_cents' => 7000]],
    ], $budget);

    expect($budget->lines)->toHaveCount(1)
        ->and($budget->lines->first()->account_id)->toBe($this->expense->id)
        ->and($budget->lines->first()->month_1_cents)->toBe(7000);
});

it('skips all-zero lines', function () {
    $budget = app(SaveBudget::class)->handle([
        'name' => 'Budget', 'fiscal_year' => 2026,
        'lines' => [
            ['account_id' => $this->income->id, 'month_1_cents' => 1000],
            ['account_id' => $this->expense->id], // all zero
        ],
    ]);

    expect($budget->lines)->toHaveCount(1)
        ->and($budget->lines->first()->account_id)->toBe($this->income->id);
});

it('totals all twelve months', function () {
    $budget = app(SaveBudget::class)->handle([
        'name' => 'B', 'fiscal_year' => 2026,
        'lines' => [['account_id' => $this->income->id, 'month_1_cents' => 1000, 'month_6_cents' => 2000, 'month_12_cents' => 3000]],
    ]);

    expect($budget->lines->first()->totalCents())->toBe(6000);
});
