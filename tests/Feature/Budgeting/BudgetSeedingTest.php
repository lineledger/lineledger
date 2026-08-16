<?php

use App\Actions\Budgeting\BuildBudgetFromActuals;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('seeds a budget grid from prior-year actuals per fiscal month', function () {
    // Prior year (FY2025) income of 600.00 in Feb 2025.
    $entry = JournalEntry::create(['entry_no' => 'JE-1', 'entry_date' => '2025-02-10', 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 60000, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 60000, 'credit_cents' => 0, 'line_order' => 1]);

    $grid = app(BuildBudgetFromActuals::class)->handle($this->company, 2026);

    // Building FY2026 reads FY2025 actuals; month_2 (Feb) should be 600.00.
    expect($grid[$this->income->id][2])->toBe(60000)
        ->and($grid[$this->income->id][1])->toBe(0);
});

it('duplicates a budget with its lines via the index action', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $source = Budget::create(['name' => 'Source', 'fiscal_year' => 2026]);
    $source->lines()->create(['account_id' => $this->income->id, 'month_1_cents' => 11000, 'month_2_cents' => 22000]);

    Livewire::test('pages::budgets.index', ['company' => $this->company])
        ->call('duplicate', $source->id);

    $clone = Budget::where('name', 'Source (copy)')->firstOrFail();

    expect($clone->id)->not->toBe($source->id)
        ->and($clone->fiscal_year)->toBe(2026)
        ->and($clone->lines)->toHaveCount(1)
        ->and($clone->lines->first()->month_1_cents)->toBe(11000)
        ->and($clone->lines->first()->month_2_cents)->toBe(22000)
        ->and(Budget::count())->toBe(2);
});
