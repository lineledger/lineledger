<?php

use App\Enums\AccountSubtype;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Insights\Detectors\ReceivablesConcentrationDetector;
use App\Services\Insights\Detectors\UnmatchedBankLinesDetector;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('flags receivables concentration when one debtor dominates', function () {
    Contact::factory()->customer()->create(['ar_balance_cents' => 800_000]); // $8,000 of $10,000 = 80%
    Contact::factory()->customer()->create(['ar_balance_cents' => 100_000]);
    Contact::factory()->customer()->create(['ar_balance_cents' => 100_000]);

    $candidates = app(ReceivablesConcentrationDetector::class)
        ->detect($this->company, $this->company->currentDateTime());

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->key)->toBe('receivables-concentration');
    expect($candidates[0]->facts['top_share_pct'])->toBe(80);
    expect($candidates[0]->facts['debtor_count'])->toBe(3);
    expect($candidates[0]->facts['top_display'])->toBe('$8,000');
    expect($candidates[0]->headline)->toContain('80%');
});

it('stays silent when no debtor dominates', function () {
    foreach (range(1, 3) as $i) {
        Contact::factory()->customer()->create(['ar_balance_cents' => 100_000]);
    }

    expect(app(ReceivablesConcentrationDetector::class)->detect($this->company, $this->company->currentDateTime()))
        ->toBe([]);
});

it('stays silent with fewer than three debtors', function () {
    Contact::factory()->customer()->create(['ar_balance_cents' => 900_000]);
    Contact::factory()->customer()->create(['ar_balance_cents' => 100_000]);

    expect(app(ReceivablesConcentrationDetector::class)->detect($this->company, $this->company->currentDateTime()))
        ->toBe([]);
});

it('flags ten or more open bank statement lines', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);

    BankStatementLine::factory()->count(8)->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => '2026-05-01',
    ]);
    BankStatementLine::factory()->count(2)->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => '2026-05-15',
        'match_status' => StatementLineMatchStatus::Suggested->value,
    ]);

    $candidates = app(UnmatchedBankLinesDetector::class)
        ->detect($this->company, $this->company->currentDateTime());

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->key)->toBe('unmatched-bank-lines');
    expect($candidates[0]->facts['total_count'])->toBe(10);
    expect($candidates[0]->facts['suggested_count'])->toBe(2);
    expect($candidates[0]->headline)->toContain('10');
});

it('stays silent below ten open lines', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);

    BankStatementLine::factory()->count(9)->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
    ]);

    expect(app(UnmatchedBankLinesDetector::class)->detect($this->company, $this->company->currentDateTime()))
        ->toBe([]);
});
