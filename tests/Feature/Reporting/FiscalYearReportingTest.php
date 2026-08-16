<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Fiscal year starts in August (matches the real conversion that surfaced this).
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->retained = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::RetainedEarnings->value)->first();

    // Prior fiscal year (FY2025: Aug 2024 – Jul 2025): income 1,000, expense 300 → net income 700.
    postFiscalEntry($this->company, '2025-01-15', [[$this->bank->id, 100000, 0], [$this->income->id, 0, 100000]]);
    postFiscalEntry($this->company, '2025-02-15', [[$this->expense->id, 30000, 0], [$this->bank->id, 0, 30000]]);

    // Current fiscal year (FY2026: Aug 2025 – Jul 2026): expense 2,900.
    postFiscalEntry($this->company, '2025-11-04', [[$this->expense->id, 290000, 0], [$this->bank->id, 0, 290000]]);

    $this->calc = app(ReportCalculator::class);
    $this->asOf = CarbonImmutable::create(2026, 5, 22);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postFiscalEntry(Company $company, string $date, array $lines): void
{
    $entry = JournalEntry::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'entry_no' => uniqid('JE-'),
        'entry_date' => $date,
        'is_posted' => true,
        'posted_at' => now(),
    ]);

    foreach ($lines as $i => $line) {
        $entry->lines()->create([
            'account_id' => $line[0],
            'debit_cents' => $line[1],
            'credit_cents' => $line[2],
            'line_order' => $i,
        ]);
    }
}

it('scopes an expense account to the current fiscal year, not cumulative history', function () {
    // Cumulative would be 300 + 2,900 = 3,200; fiscal-year-scoped is just 2,900.
    expect($this->calc->reportingBalanceAsOf($this->company, $this->expense, $this->asOf))->toBe(290000)
        ->and($this->calc->balanceAsOf($this->expense, $this->asOf))->toBe(320000);
});

it('rolls prior-year net income into retained earnings', function () {
    expect($this->calc->priorRetainedEarnings($this->company, $this->asOf))->toBe(70000);
});

it('opens a P&L general ledger at zero on the fiscal year start', function () {
    $gl = $this->calc->generalLedger($this->expense, CarbonImmutable::create(2025, 8, 1), $this->asOf);

    expect($gl['opening'])->toBe(0)
        ->and($gl['closing'])->toBe(290000);
});

it('produces a balanced trial balance with prior earnings in retained earnings', function () {
    $tb = $this->calc->trialBalance($this->company, $this->asOf);

    $byId = $tb->keyBy(fn ($r) => $r['account']->id);

    // Expense shows current-year only; retained earnings holds prior net income.
    expect($byId[$this->expense->id]['balance'])->toBe(290000)
        ->and($byId[$this->retained->id]['balance'])->toBe(70000)
        ->and($byId->has($this->income->id))->toBeFalse(); // no current-year income → filtered out

    // Debits must equal credits.
    $debit = 0;
    $credit = 0;
    foreach ($tb as $row) {
        $natural = $row['balance'];
        $isDebitNormal = $row['account']->normal_balance->value === 'debit';
        if ($isDebitNormal) {
            $natural > 0 ? $debit += $natural : $credit += -$natural;
        } else {
            $natural > 0 ? $credit += $natural : $debit += -$natural;
        }
    }

    expect($debit)->toBe($credit);
});
