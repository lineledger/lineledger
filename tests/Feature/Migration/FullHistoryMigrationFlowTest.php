<?php

use App\Enums\AccountSubtype;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use App\Services\Migration\QuickBooksMigrationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('starts a full-history run with the full-history step sequence', function () {
    $run = app(QuickBooksMigrationService::class)->startOrResume($this->company, mode: DataMigrationMode::FullHistory);

    expect($run->modeEnum())->toBe(DataMigrationMode::FullHistory)
        ->and($run->steps())->toBe([
            1 => 'setup',
            2 => 'chart_of_accounts',
            3 => 'confirm_control_accounts',
            4 => 'customers',
            5 => 'vendors',
            6 => 'general_ledger',
            7 => 'open_invoices',
            8 => 'open_bills',
            9 => 'review',
        ]);
});

it('will not switch modes once a step beyond setup is committed', function () {
    $service = app(QuickBooksMigrationService::class);
    $run = $service->startOrResume($this->company, mode: DataMigrationMode::FullHistory);

    // Fresh run can switch.
    $switched = $service->startOrResume($this->company, mode: DataMigrationMode::OpeningBalance);
    expect($switched->modeEnum())->toBe(DataMigrationMode::OpeningBalance);

    // Commit a non-setup step, then switching is refused.
    $switched->recordStepResult('customers', ['created' => 1]);
    $blocked = $service->startOrResume($this->company, mode: DataMigrationMode::FullHistory);
    expect($blocked->modeEnum())->toBe(DataMigrationMode::OpeningBalance);
});

it('does not lock the books on finalize by default, but can lock at the history start date', function () {
    $service = app(QuickBooksMigrationService::class);

    $run = $service->startOrResume($this->company, mode: DataMigrationMode::FullHistory);
    $service->finalize($run);
    expect($this->company->fresh()->lock_date)->toBeNull();

    // With an explicit lock + history start date.
    $run2 = $service->startOrResume($this->company->fresh(), mode: DataMigrationMode::FullHistory);
    $run2->forceFill(['status' => DataMigrationStatus::InProgress, 'history_start_date' => CarbonImmutable::create(2020, 1, 1)])->save();
    $service->finalize($run2->fresh(), lockBooks: true);

    expect($this->company->fresh()->lock_date->toDateString())->toBe('2020-01-01');
});

it('rolls back exactly the replayed entries and recomputes balances to zero', function () {
    $service = app(QuickBooksMigrationService::class);
    $run = $service->startOrResume($this->company, mode: DataMigrationMode::FullHistory);

    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $ctx = new ImportContext(
        company: $this->company,
        run: $run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        sourceFormat: 'csv',
    );

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Deposit,2024-01-01,,,,{$bank->name},500.00,\n"
        .",,,,,,{$income->name},,500.00\n";
    $path = tempnam(sys_get_temp_dir(), 'gl').'.csv';
    file_put_contents($path, $csv);

    app(GeneralLedgerReplayImporter::class)->commit($path, $ctx);

    $bank->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(50000);

    $removed = $service->rollbackFullHistory($run->fresh());

    expect($removed)->toBe(1)
        ->and(JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', 'qbd_import')->count())->toBe(0);

    $bank->refresh()->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(0);
});

it('reproduces a balanced ledger across a full historical year', function () {
    $service = app(QuickBooksMigrationService::class);
    $run = $service->startOrResume($this->company, mode: DataMigrationMode::FullHistory);

    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $expense = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $ctx = new ImportContext(
        company: $this->company,
        run: $run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        sourceFormat: 'csv',
    );

    // Invoice (AR/income), customer payment (bank/AR), an expense (expense/bank).
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-10,INV-1,Acme,,{$ar->name},2000.00,\n"
        .",,,,,,{$income->name},,2000.00\n"
        ."2,Payment,2024-02-10,,Acme,,{$bank->name},2000.00,\n"
        .",,,,,,{$ar->name},,2000.00\n"
        ."3,Check,2024-03-10,,Landlord,,{$expense->name},800.00,\n"
        .",,,,,,{$bank->name},,800.00\n";
    $path = tempnam(sys_get_temp_dir(), 'gl').'.csv';
    file_put_contents($path, $csv);

    $result = app(GeneralLedgerReplayImporter::class)->commit($path, $ctx);
    expect($result->isOk())->toBeTrue()->and($result->summary['committed'])->toBe(3);

    // Ledger integrity: total debits == total credits across all posted lines.
    $totals = JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.company_id', $this->company->id)
        ->where('journal_entries.is_posted', true)
        ->selectRaw('SUM(debit_cents) d, SUM(credit_cents) c')
        ->first();
    expect((int) $totals->d)->toBe((int) $totals->c);

    // Account balances reflect the replayed history.
    foreach ([$bank, $ar, $income, $expense] as $account) {
        $account->recomputeBalance();
    }

    expect((int) $bank->balance_cents)->toBe(120000)   // +2000 -800
        ->and((int) $ar->balance_cents)->toBe(0)        // +2000 -2000
        ->and((int) $income->balance_cents)->toBe(200000)
        ->and((int) $expense->balance_cents)->toBe(80000);
});
