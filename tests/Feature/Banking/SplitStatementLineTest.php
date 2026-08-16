<?php

use App\Actions\Banking\SplitStatementLine;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->orderBy('code')->firstOrFail();
    [$this->expenseA, $this->expenseB] = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->take(2)->get()->all();

    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function statementLine(int $amountCents): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $amountCents,
        'description' => 'SPLIT ME',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'created_journal_entry_id' => null,
    ]);
}

it('splits an inflow into a multi-line deposit and ticks the line Created', function () {
    $line = statementLine(10000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->income->id, 'amount_cents' => 6000],
        ['account_id' => $this->equity->id, 'amount_cents' => 4000],
    ]);

    $line->refresh();
    expect($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->created_journal_entry_id)->not->toBeNull()
        ->and($line->matched_journal_line_id)->not->toBeNull();

    $entry = JournalEntry::findOrFail($line->created_journal_entry_id);
    $entry->load('lines');
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(10000)
        ->and($entry->lines->firstWhere('account_id', $this->income->id)->credit_cents)->toBe(6000)
        ->and($entry->lines->firstWhere('account_id', $this->equity->id)->credit_cents)->toBe(4000);
});

it('splits an outflow into a multi-line expense', function () {
    $line = statementLine(-6000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->expenseA->id, 'amount_cents' => 4000],
        ['account_id' => $this->expenseB->id, 'amount_cents' => 2000],
    ]);

    $entry = JournalEntry::findOrFail($line->fresh()->created_journal_entry_id);
    $entry->load('lines');
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->credit_cents)->toBe(6000)
        ->and($entry->lines->firstWhere('account_id', $this->expenseA->id)->debit_cents)->toBe(4000)
        ->and($entry->lines->firstWhere('account_id', $this->expenseB->id)->debit_cents)->toBe(2000);
});

it('rejects a split that does not sum to the transaction total and posts nothing', function () {
    $line = statementLine(10000);

    expect(fn () => app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->income->id, 'amount_cents' => 6000],
        ['account_id' => $this->equity->id, 'amount_cents' => 3000], // sums to 9000, not 10000
    ]))->toThrow(PostingValidationException::class);

    expect(JournalEntry::count())->toBe(0)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('refuses to split a line that was already added', function () {
    $line = statementLine(10000);
    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->income->id, 'amount_cents' => 10000],
    ]);

    expect(fn () => app(SplitStatementLine::class)->handle($line->fresh(), [
        ['account_id' => $this->income->id, 'amount_cents' => 10000],
    ]))->toThrow(PostingValidationException::class);

    expect(JournalEntry::count())->toBe(1); // only the first split posted
});
