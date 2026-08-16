<?php

use App\Actions\Accounting\MergeAccounts;
use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\BankReconciliation;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use App\Models\User;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function mergeTestAccount(string $code, string $name, AccountSubtype $subtype = AccountSubtype::Expense, array $overrides = []): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'subtype' => $subtype,
        'type' => $subtype->type(),
        'normal_balance' => $subtype->type()->normalBalance(),
        'is_active' => true,
        ...$overrides,
    ]);
}

/**
 * Run a merge that is expected to be blocked and return the first guard message.
 */
function mergeAccountsGuardMessage(Account $loser, Account $survivor): string
{
    try {
        app(MergeAccounts::class)->handle($loser, $survivor);
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    test()->fail('Expected the merge to be blocked by a guard, but it succeeded.');
}

it('merges one account into another, repointing every reference', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software');

    // Posted GL activity on both sides: loser 10000, survivor 5000.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => now()->toDateString(),
        'memo' => 'Merge seed',
        'lines' => [
            ['account_id' => $loser->id, 'debit_cents' => 10000, 'credit_cents' => 0],
            ['account_id' => $survivor->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 15000],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    $item = Item::factory()->create(['expense_account_id' => $loser->id]);
    $child = mergeTestAccount('6102', 'Software sub (dup)', AccountSubtype::Expense, ['parent_id' => $loser->id]);
    $contact = Contact::factory()->vendor()->create(['default_expense_account_id' => $loser->id]);

    $result = app(MergeAccounts::class)->handle($loser, $survivor);

    expect($result->id)->toBe($survivor->id);

    expect(DB::table('journal_lines')->where('account_id', $loser->id)->count())->toBe(0)
        ->and(DB::table('journal_lines')->where('account_id', $survivor->id)->count())->toBe(2)
        ->and($item->fresh()->expense_account_id)->toBe($survivor->id)
        ->and($child->fresh()->parent_id)->toBe($survivor->id)
        ->and($contact->fresh()->default_expense_account_id)->toBe($survivor->id);

    // Survivor balance recomputed: its own 5000 plus the loser's 10000.
    expect($survivor->fresh()->balance_cents)->toBe(15000);

    $trashed = Account::withTrashed()->find($loser->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->is_active)->toBeFalse()
        ->and((int) $trashed->balance_cents)->toBe(0);
});

it('writes two account.merged audit rows and keeps the hash chain valid', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software');

    app(MergeAccounts::class)->handle($loser, $survivor);

    $rows = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::AccountMerged)
        ->orderBy('sequence')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('auditable_id')->all())->toContain($loser->id, $survivor->id);

    $loserRow = $rows->firstWhere('auditable_id', $loser->id);
    expect($loserRow->payload['merged_into']['id'])->toBe($survivor->id);

    $survivorRow = $rows->firstWhere('auditable_id', $survivor->id);
    expect($survivorRow->payload['absorbed']['id'])->toBe($loser->id);

    // The whole company chain still verifies link by link.
    $all = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->orderBy('sequence')
        ->get();

    $prev = AccountingAuditRecorder::GENESIS_HASH;
    foreach ($all as $row) {
        expect($row->previous_hash)->toBe($prev)
            ->and($row->row_hash)->toBe(AccountingAuditRecorder::hashFromInput($row->previous_hash, $row->hash_input));
        $prev = $row->row_hash;
    }
});

it('blocks merging an account into itself', function () {
    $account = mergeTestAccount('6101', 'Software');

    expect(mergeAccountsGuardMessage($account, $account))->toContain('itself');
});

it('blocks merging across companies', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');

    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = mergeTestAccount('6201', 'Software');
    app()->instance('current_company', $this->company);

    expect(mergeAccountsGuardMessage($loser, $foreign))->toContain('current company');
});

it('blocks merging into an inactive survivor', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software', AccountSubtype::Expense, ['is_active' => false]);

    expect(mergeAccountsGuardMessage($loser, $survivor))->toContain('must be active');
});

it('blocks merging a system account away but allows a system survivor', function () {
    $system = Account::query()->where('is_system', true)->first();
    $twin = mergeTestAccount('9901', 'Twin of system', $system->subtype);

    expect(mergeAccountsGuardMessage($system, $twin))->toContain('System accounts cannot be merged away.');

    // The reverse direction — absorbing INTO the system account — is allowed.
    app(MergeAccounts::class)->handle($twin, $system);
    expect(Account::withTrashed()->find($twin->id)->trashed())->toBeTrue();
});

it('blocks merging accounts of different subtypes', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    expect(mergeAccountsGuardMessage($loser, $income))->toContain('same type');
});

it('blocks merging accounts of different currencies', function () {
    $usdBank = mergeTestAccount('1051', 'USD Chequing', AccountSubtype::Bank, ['currency_code' => 'USD']);

    expect(mergeAccountsGuardMessage($usdBank, $this->bank))->toContain('same currency');
});

it('blocks merging an account into one of its own sub-accounts', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $child = mergeTestAccount('6102', 'Software child', AccountSubtype::Expense, ['parent_id' => $loser->id]);

    expect(mergeAccountsGuardMessage($loser, $child))->toContain('sub-account');
});

it('blocks the merge when both accounts have lines in the same budget, naming it', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software');

    $budget = Budget::create(['name' => 'FY26 Operating', 'fiscal_year' => 2026]);
    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $loser->id, 'month_1_cents' => 100]);
    BudgetLine::create(['budget_id' => $budget->id, 'account_id' => $survivor->id, 'month_1_cents' => 200]);

    expect(mergeAccountsGuardMessage($loser, $survivor))->toContain('FY26 Operating');
});

it('blocks merging away a bank account with reconciliation history', function () {
    $loser = mergeTestAccount('1051', 'Chequing (dup)', AccountSubtype::Bank);
    BankReconciliation::factory()->create(['account_id' => $loser->id]);

    expect(mergeAccountsGuardMessage($loser, $this->bank))->toContain('reconciliation history');
});

it('is not blocked by a lock_date in the past', function () {
    $this->company->update(['lock_date' => now()->subYear()->toDateString()]);

    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software');

    app(MergeAccounts::class)->handle($loser, $survivor);

    expect(Account::withTrashed()->find($loser->id)->trashed())->toBeTrue();
});

it('collapses a report group mapping when both accounts map into the same group', function () {
    $loser = mergeTestAccount('6101', 'Software (dup)');
    $survivor = mergeTestAccount('6201', 'Software');

    $group = ReportGroup::factory()->create(['user_id' => $this->user->id]);
    $line = ReportGroupLine::factory()->create(['report_group_id' => $group->id]);

    ReportGroupAccountMap::create([
        'report_group_id' => $group->id,
        'report_group_line_id' => $line->id,
        'company_id' => $this->company->id,
        'account_id' => $loser->id,
    ]);
    ReportGroupAccountMap::create([
        'report_group_id' => $group->id,
        'report_group_line_id' => $line->id,
        'company_id' => $this->company->id,
        'account_id' => $survivor->id,
    ]);

    app(MergeAccounts::class)->handle($loser, $survivor);

    $maps = ReportGroupAccountMap::query()->where('report_group_id', $group->id)->get();

    expect($maps)->toHaveCount(1)
        ->and($maps->first()->account_id)->toBe($survivor->id);
});
