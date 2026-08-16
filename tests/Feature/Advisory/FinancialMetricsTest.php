<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Reporting\FinancialMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** First seeded account of a subtype (no global scope). */
function fmAccount(Company $company, AccountSubtype $subtype): Account
{
    return Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('subtype', $subtype->value)
        ->orderBy('code')
        ->firstOrFail();
}

/**
 * Post a balanced, posted manual journal entry.
 *
 * @param  array<int, array{account: Account, debit?: int, credit?: int}>  $lines
 */
function fmPost(Company $company, string $date, array $lines): void
{
    app()->instance('current_company', $company);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.fake()->unique()->numerify('######'),
        'entry_date' => CarbonImmutable::parse($date),
        'memo' => 'Advisory metrics test',
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

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = fmAccount($this->company, AccountSubtype::Bank);
    $this->income = fmAccount($this->company, AccountSubtype::Income);
    $this->expense = fmAccount($this->company, AccountSubtype::Expense);
    $this->cogs = fmAccount($this->company, AccountSubtype::CostOfGoodsSold);
    $this->ar = fmAccount($this->company, AccountSubtype::AccountsReceivable);
    $this->ap = fmAccount($this->company, AccountSubtype::AccountsPayable);
    $this->inventory = fmAccount($this->company, AccountSubtype::Inventory);

    // Q1 2026 activity:
    fmPost($this->company, '2026-01-10', [['account' => $this->bank, 'debit' => 100000], ['account' => $this->income, 'credit' => 100000]]);   // cash sale $1,000
    fmPost($this->company, '2026-02-15', [['account' => $this->ar, 'debit' => 50000], ['account' => $this->income, 'credit' => 50000]]);        // credit sale $500 (AR)
    fmPost($this->company, '2026-02-20', [['account' => $this->cogs, 'debit' => 30000], ['account' => $this->inventory, 'credit' => 30000]]);   // COGS $300
    fmPost($this->company, '2026-03-01', [['account' => $this->expense, 'debit' => 20000], ['account' => $this->bank, 'credit' => 20000]]);     // opex $200 (cash)
    fmPost($this->company, '2026-03-05', [['account' => $this->expense, 'debit' => 40000], ['account' => $this->ap, 'credit' => 40000]]);       // opex $400 (bill)
    fmPost($this->company, '2026-03-10', [['account' => $this->inventory, 'debit' => 80000], ['account' => $this->ap, 'credit' => 80000]]);     // inventory purchase $800 (bill)
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('computes period flow figures from the GL', function () {
    $period = app(FinancialMetrics::class)->period(
        $this->company,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 3, 31),
    );

    expect($period['revenue_cents'])->toBe(150000)
        ->and($period['cogs_cents'])->toBe(30000)
        ->and($period['gross_profit_cents'])->toBe(120000)
        ->and($period['operating_expense_cents'])->toBe(60000)
        ->and($period['net_income_cents'])->toBe(60000)
        ->and($period['days'])->toBe(90)
        ->and($period['revenue_display'])->toBe('$1,500')
        ->and($period['net_income_display'])->toBe('$600');
});

it('computes the end-of-period balance snapshot', function () {
    $period = app(FinancialMetrics::class)->period(
        $this->company,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 3, 31),
    );

    expect($period['cash_cents'])->toBe(80000)                  // 100000 in − 20000 opex
        ->and($period['ar_cents'])->toBe(50000)
        ->and($period['ap_cents'])->toBe(120000)                // 40000 + 80000
        ->and($period['inventory_cents'])->toBe(50000)          // 80000 − 30000
        ->and($period['current_assets_cents'])->toBe(180000)    // 80000 + 50000 + 50000
        ->and($period['current_liabilities_cents'])->toBe(120000);
});

it('counts activity dated on the period start (SQLite boundary day)', function () {
    fmPost($this->company, '2026-04-01', [['account' => $this->bank, 'debit' => 7000], ['account' => $this->income, 'credit' => 7000]]);

    $period = app(FinancialMetrics::class)->period(
        $this->company,
        CarbonImmutable::create(2026, 4, 1),
        CarbonImmutable::create(2026, 4, 30),
    );

    expect($period['revenue_cents'])->toBe(7000);
});

it('builds a monthly series ending in the current month', function () {
    Carbon::setTestNow(CarbonImmutable::create(2026, 3, 15));

    $series = app(FinancialMetrics::class)->monthlySeries($this->company, 3);

    expect($series)->toHaveCount(3)
        ->and($series[0]['label'])->toBe('Jan 2026')
        ->and($series[0]['income_cents'])->toBe(100000)
        ->and($series[1]['label'])->toBe('Feb 2026')
        ->and($series[1]['income_cents'])->toBe(50000)
        ->and($series[2]['label'])->toBe('Mar 2026')
        ->and($series[2]['expense_cents'])->toBe(60000);

    Carbon::setTestNow();
});
