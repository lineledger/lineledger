<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $this->user = User::factory()->create();
    // Year-end July 31: the current fiscal year began 2026-08-01.
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->prepaid = Account::query()->where('subtype', AccountSubtype::CurrentAsset->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function completedRecForJournalTest(Account $bank, string $statementDate): BankReconciliation
{
    return BankReconciliation::factory()->completed()->create([
        'company_id' => $bank->company_id,
        'account_id' => $bank->id,
        'statement_date' => $statementDate,
    ]);
}

function postedJournalEntry(string $date, Account $debit, Account $credit, int $cents = 10000): JournalEntry
{
    $entry = JournalEntry::create(['entry_no' => 'JE-'.uniqid(), 'entry_date' => $date, 'memo' => 'Posted']);
    $entry->lines()->createMany([
        ['account_id' => $debit->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1],
    ]);

    return app(JournalPoster::class)->post($entry);
}

it('asks before posting into a reconciled period, then posts once confirmed', function () {
    completedRecForJournalTest($this->bank, '2026-09-30');

    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-09-15')
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($component->get('backdateWarnings'))->toHaveCount(1)
        ->and($component->get('backdateWarnings')[0])->toContain('uncleared item on your next reconciliation')
        ->and(JournalEntry::query()->where('is_posted', true)->count())->toBe(0);

    $component->call('confirmBackdated')->assertHasNoErrors()->assertRedirect();

    $entry = JournalEntry::query()->where('is_posted', true)->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-09-15')
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->bank_reconciliation_id)->toBeNull();
});

it('warns when posting an adjusting entry to the prior fiscal year', function () {
    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-07-31')
        ->set('lines.0.account_id', $this->prepaid->id)
        ->set('lines.0.debit', '250.00')
        ->set('lines.1.account_id', $this->expense->id)
        ->set('lines.1.credit', '250.00')
        ->call('postEntry')
        ->assertNoRedirect();

    expect($component->get('backdateWarnings'))->toHaveCount(1)
        ->and($component->get('backdateWarnings')[0])->toContain('prior fiscal year');
});

it('lists both warnings for a year-end entry that hits a reconciled bank account', function () {
    completedRecForJournalTest($this->bank, '2026-09-30');

    $component = Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-07-31')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.debit', '25.00')
        ->set('lines.1.account_id', $this->bank->id)
        ->set('lines.1.credit', '25.00')
        ->call('postEntry')
        ->assertNoRedirect();

    expect($component->get('backdateWarnings'))->toHaveCount(2);
});

it('posts a current-year entry straight through with no dialog', function () {
    completedRecForJournalTest($this->bank, '2026-09-30');

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('entryDate', '2026-09-15')
        ->set('lines.0.account_id', $this->prepaid->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $this->expense->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors()
        ->assertSet('backdateWarnings', [])
        ->assertRedirect();
});

it('refuses to save changes to a posted entry whose bank line is reconciled', function () {
    $entry = postedJournalEntry('2026-09-10', $this->bank, $this->income);
    claimLinesForReconciliation(completedRecForJournalTest($this->bank, '2026-09-30'), $entry);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry->fresh()])
        ->set('lines.0.debit', '999.00')
        ->set('lines.1.credit', '999.00')
        ->call('saveChanges')
        ->assertHasErrors('lines')
        ->assertNoRedirect();

    expect($entry->fresh()->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(10000);
});

it('stops moving a posted entry out of a locked period until the lock is overridden', function () {
    $entry = postedJournalEntry('2026-08-10', $this->prepaid, $this->expense);
    $this->company->update(['lock_date' => '2026-08-31']);

    // The owner is offered the Block-mode password override instead of a save.
    Livewire::test('pages::journal.form', ['company' => $this->company->fresh(), 'entry' => $entry->fresh()])
        ->set('entryDate', '2026-09-15')
        ->call('saveChanges')
        ->assertSet('periodLockRetryAction', 'saveChanges')
        ->assertNoRedirect();

    expect($entry->fresh()->entry_date->toDateString())->toBe('2026-08-10');
});
