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
use App\Models\Contact;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

it('applies tax codes per split part, tax-inclusive, and still balances to the total', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $line = statementLine(-10000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->expenseA->id, 'amount_cents' => 6000, 'tax_code_id' => $gst->id],
        ['account_id' => $this->expenseB->id, 'amount_cents' => 4000],
    ]);

    $entry = JournalEntry::findOrFail($line->fresh()->created_journal_entry_id)->load('lines');

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $this->expenseA->id)->debit_cents)->toBe(5714)
        ->and($entry->lines->firstWhere('account_id', $gst->agency->payable_account_id)->debit_cents)->toBe(286)
        ->and($entry->lines->firstWhere('account_id', $this->expenseB->id)->debit_cents)->toBe(4000)
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->credit_cents)->toBe(10000);
});

it('records an outflow split as an expense to the chosen payee and stamps the line', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Split Vendor']);
    $line = statementLine(-9000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->expenseA->id, 'amount_cents' => 5000],
        ['account_id' => $this->expenseB->id, 'amount_cents' => 4000],
    ], $vendor->id);

    $expense = Expense::query()->firstOrFail();

    expect($expense->payee_contact_id)->toBe($vendor->id)
        ->and($expense->payee_name)->toBe('Split Vendor')
        ->and($line->fresh()->suggested_contact_id)->toBe($vendor->id)
        ->and(JournalEntry::findOrFail($line->fresh()->created_journal_entry_id)->lines()->where('account_id', $this->bank->id)->value('contact_id'))->toBe($vendor->id);
});

it('defaults inflow split lines to the header payee and refuses a payee that no longer exists', function () {
    $customer = Contact::factory()->customer()->create();
    $line = statementLine(7000);

    app(SplitStatementLine::class)->handle($line, [
        ['account_id' => $this->income->id, 'amount_cents' => 4000],
        ['account_id' => $this->equity->id, 'amount_cents' => 3000, 'contact_id' => null],
    ], $customer->id);

    expect(DB::table('deposit_lines')->where('contact_id', $customer->id)->count())->toBe(2);

    $gone = Contact::factory()->vendor()->create();
    $gone->delete();

    expect(fn () => app(SplitStatementLine::class)->handle(statementLine(-1000), [
        ['account_id' => $this->expenseA->id, 'amount_cents' => 1000],
    ], $gone->id))->toThrow(PostingValidationException::class, 'no longer exists');
});
