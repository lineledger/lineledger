<?php

use App\Actions\Accounting\MergeAccounts;
use App\Actions\Accounting\PostAccountOpeningBalance;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRow;
use App\Models\OpeningBalanceState;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->state = OpeningBalanceState::create([
        'company_id' => $this->company->id,
        'as_of_date' => '2026-06-30',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function obAccount(string $code): Account
{
    return Account::query()->where('code', $code)->firstOrFail();
}

function obTarget(OpeningBalanceState $state, string $code, int $debit = 0, int $credit = 0): OpeningBalanceRow
{
    return OpeningBalanceRow::updateOrCreate(
        ['opening_balance_state_id' => $state->id, 'account_id' => obAccount($code)->id],
        ['company_id' => $state->company_id, 'debit_cents' => $debit, 'credit_cents' => $credit],
    );
}

function obSync(): OpeningBalanceJournalSynchronizer
{
    return app(OpeningBalanceJournalSynchronizer::class);
}

it('creates one balanced posted entry with an OBE plug', function () {
    obTarget($this->state, '1000', debit: 100000);   // Chequing
    obTarget($this->state, '1300', debit: 50000);    // Prepaid Expenses
    obTarget($this->state, '2700', credit: 30000);   // Bank Loan

    $entry = obSync()->apply($this->state->refresh());

    expect($entry)->not->toBeNull();
    expect($entry->isPosted())->toBeTrue();
    expect($entry->entry_date->toDateString())->toBe('2026-06-30');
    expect($entry->isBalanced())->toBeTrue();

    $obe = obAccount('3000');
    expect($entry->lines->firstWhere('account_id', obAccount('1000')->id)->debit_cents)->toBe(100000);
    expect($entry->lines->firstWhere('account_id', obAccount('1300')->id)->debit_cents)->toBe(50000);
    expect($entry->lines->firstWhere('account_id', obAccount('2700')->id)->credit_cents)->toBe(30000);
    expect($entry->lines->firstWhere('account_id', $obe->id)->credit_cents)->toBe(120000);

    expect($this->state->refresh()->journal_entry_id)->toBe($entry->id);
    expect($this->state->apply_error)->toBeNull();
});

it('reposts the same entry in place when a target changes', function () {
    obTarget($this->state, '1000', debit: 100000);
    $first = obSync()->apply($this->state->refresh());

    obTarget($this->state, '1000', debit: 80000);
    $second = obSync()->apply($this->state->refresh());

    expect($second->id)->toBe($first->id);
    expect($second->entry_no)->toBe($first->entry_no);
    expect($second->lines->firstWhere('account_id', obAccount('1000')->id)->debit_cents)->toBe(80000);
    expect($second->lines->firstWhere('account_id', obAccount('3000')->id)->credit_cents)->toBe(80000);
    expect(JournalEntry::query()->count())->toBe(1);
});

it('no-ops without a new audit row when nothing changed', function () {
    obTarget($this->state, '1000', debit: 100000);
    $entry = obSync()->apply($this->state->refresh());

    $auditCount = AccountingAuditLog::withoutGlobalScopes()->count();

    $again = obSync()->apply($this->state->refresh());

    expect($again->id)->toBe($entry->id);
    expect(AccountingAuditLog::withoutGlobalScopes()->count())->toBe($auditCount);
    expect(obSync()->isDirty($this->state->refresh()))->toBeFalse();
});

it('nets against pre-existing opening entries instead of double-counting', function () {
    // The scattered accounts-page mechanism already posted $200 to Prepaid.
    app(PostAccountOpeningBalance::class)->handle(obAccount('1300'), 20000, '2026-06-30');

    obTarget($this->state, '1300', debit: 50000);
    $entry = obSync()->apply($this->state->refresh());

    // Maintained line is only the difference…
    expect($entry->lines->firstWhere('account_id', obAccount('1300')->id)->debit_cents)->toBe(30000);

    // …so the reported as-of balance lands exactly on the target.
    $report = app(ReportCalculator::class)->trialBalance($this->company, CarbonImmutable::parse('2026-06-30'));
    $prepaid = $report->firstWhere(fn ($row) => $row['account']->code === '1300');
    expect($prepaid['balance'])->toBe(50000);
});

it('never writes lines on AR, AP or Inventory control accounts', function () {
    obTarget($this->state, '1000', debit: 10000);
    obTarget($this->state, '1100', debit: 55500);   // Accounts Receivable target
    obTarget($this->state, '2000', credit: 44400);  // Accounts Payable target
    obTarget($this->state, '1400', debit: 12300);   // Inventory target

    $entry = obSync()->apply($this->state->refresh());

    $lineAccounts = $entry->lines->pluck('account_id');
    expect($lineAccounts)->not->toContain(obAccount('1100')->id);
    expect($lineAccounts)->not->toContain(obAccount('2000')->id);
    expect($lineAccounts)->not->toContain(obAccount('1400')->id);

    // Bank line + plug only.
    expect($entry->lines)->toHaveCount(2);
});

it('voids the maintained entry when every target is removed', function () {
    obTarget($this->state, '1000', debit: 100000);
    $entry = obSync()->apply($this->state->refresh());

    OpeningBalanceRow::query()->delete();

    $result = obSync()->apply($this->state->refresh());

    expect($result)->toBeNull();
    expect($entry->fresh()->isVoided())->toBeTrue();
    expect($this->state->refresh()->journal_entry_id)->toBeNull();
});

it('records a period lock quietly and applies cleanly after the lock is lifted', function () {
    $this->company->update(['lock_date' => '2026-12-31']);

    obTarget($this->state, '1000', debit: 100000);
    $result = obSync()->applyQuietly($this->state->refresh());

    expect($result)->toBeNull();
    expect($this->state->refresh()->apply_error)->not->toBeNull();
    expect($this->state->journal_entry_id)->toBeNull();

    $this->company->update(['lock_date' => null]);

    $entry = obSync()->applyQuietly($this->state->refresh());

    expect($entry)->not->toBeNull();
    expect($this->state->refresh()->apply_error)->toBeNull();
});

it('re-dates the maintained entry when the as-of date moves', function () {
    obTarget($this->state, '1000', debit: 100000);
    $entry = obSync()->apply($this->state->refresh());

    $this->state->update(['as_of_date' => '2026-07-31']);

    $entry = obSync()->apply($this->state->refresh());

    expect($entry->entry_date->toDateString())->toBe('2026-07-31');
    expect($entry->lines()->first()->entry_date->toDateString())->toBe('2026-07-31');
});

it('stamps bank and credit-card lines cleared, leaving the plug untouched', function () {
    obTarget($this->state, '1000', debit: 100000);  // Bank
    obTarget($this->state, '2100', credit: 20000);  // Credit Card
    obTarget($this->state, '1300', debit: 5000);    // Current asset

    $entry = obSync()->apply($this->state->refresh());

    $bankLine = $entry->lines()->where('account_id', obAccount('1000')->id)->first();
    $cardLine = $entry->lines()->where('account_id', obAccount('2100')->id)->first();
    $prepaidLine = $entry->lines()->where('account_id', obAccount('1300')->id)->first();
    $obeLine = $entry->lines()->where('account_id', obAccount('3000')->id)->first();

    expect($bankLine->cleared_at)->not->toBeNull();
    expect($bankLine->bank_reconciliation_id)->toBeNull();
    expect($cardLine->cleared_at)->not->toBeNull();
    expect($prepaidLine->cleared_at)->toBeNull();
    expect($obeLine->cleared_at)->toBeNull();
});

it('keeps the audit chain intact across repeated applies', function () {
    obTarget($this->state, '1000', debit: 100000);
    obSync()->apply($this->state->refresh());

    obTarget($this->state, '1000', debit: 90000);
    obSync()->apply($this->state->refresh());

    obTarget($this->state, '2700', credit: 15000);
    obSync()->apply($this->state->refresh());

    $rows = AccountingAuditLog::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->orderBy('sequence')
        ->get();

    expect($rows)->not->toBeEmpty();

    $previous = str_repeat('0', 64);

    foreach ($rows as $row) {
        expect($row->previous_hash)->toBe($previous);
        expect($row->row_hash)->toBe(
            AccountingAuditRecorder::hashFromInput($row->previous_hash, $row->hash_input),
        );
        $previous = $row->row_hash;
    }
});

it('folds draft targets together when their accounts are merged', function () {
    obTarget($this->state, '6000', debit: 50000);   // Advertising (survivor)
    obTarget($this->state, '6010', debit: 20000);   // Bank Charges (loser)

    $survivor = obAccount('6000');
    $loser = obAccount('6010');

    app(MergeAccounts::class)->handle($loser, $survivor);

    $rows = $this->state->rows()->get();
    expect($rows)->toHaveCount(1);
    expect((int) $rows->first()->account_id)->toBe($survivor->id);
    expect((int) $rows->first()->debit_cents)->toBe(70000);

    // A loser-only target simply repoints.
    obTarget($this->state, '1010', debit: 5000);    // Savings
    app(MergeAccounts::class)->handle(obAccount('1010'), obAccount('1000'));

    expect((int) $this->state->rows()->where('account_id', obAccount('1000')->id)->first()->debit_cents)->toBe(5000);
});

it('refuses to apply when finalized', function () {
    obTarget($this->state, '1000', debit: 100000);
    $this->state->update(['status' => OpeningBalanceState::STATUS_FINALIZED]);

    expect(fn () => obSync()->apply($this->state->refresh()))
        ->toThrow(RuntimeException::class);
});
