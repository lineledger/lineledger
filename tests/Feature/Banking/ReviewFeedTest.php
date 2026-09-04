<?php

use App\Actions\Banking\BulkCategorizeStatementLines;
use App\Actions\Banking\BulkSetStatementLineStatus;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->card = Account::query()->where('subtype', AccountSubtype::CreditCard->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function reviewLine(int $amount, string $status = 'unmatched', ?int $suggested = null, string $date = '2026-06-10'): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => $date,
        'amount_cents' => $amount,
        'description' => 'TXN '.abs($amount),
        'match_status' => $status,
        'suggested_account_id' => $suggested,
        'created_journal_entry_id' => null,
    ]);
}

it('lists only un-posted, reviewable lines', function () {
    reviewLine(10000);
    reviewLine(-5000, 'suggested', test()->expense->id);
    // Already added — must not appear.
    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $this->import->id, 'account_id' => $this->bank->id,
        'amount_cents' => 999, 'match_status' => StatementLineMatchStatus::Created->value,
        'created_journal_entry_id' => null, 'matched_journal_line_id' => null,
    ]);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])->assertOk();

    expect(substr_count($page->html(), 'data-test="review-row"'))->toBe(2);
    $page->assertSee('TXN 10000')->assertSee('TXN 5000')->assertDontSee('TXN 999');
});

it('accepts a line, posting it and dropping it from the feed', function () {
    $line = reviewLine(10000);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('categories', [$line->id => $this->income->id])
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    $line->refresh();
    expect($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->created_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::count())->toBe(1);

    expect(substr_count($page->html(), 'data-test="review-row"'))->toBe(0);
});

it('accepting twice does not double-post', function () {
    $line = reviewLine(10000, 'suggested', $this->income->id);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('accept', $line->id)
        ->call('accept', $line->id); // line no longer forReview — no-op

    expect(JournalEntry::count())->toBe(1);
});

it('excludes a line', function () {
    $line = reviewLine(-2500);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('exclude', $line->id);

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Ignored)
        ->and(JournalEntry::count())->toBe(0);
});

it('splits a line through the modal', function () {
    $line = reviewLine(10000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $line->id)
        ->assertSet('splitTargetCents', 10000)
        ->set('splits', [
            ['account_id' => $this->income->id, 'amount' => '60.00'],
            ['account_id' => $this->income->id, 'amount' => '40.00'],
        ])
        ->call('saveSplit')
        ->assertHasNoErrors()
        ->assertSet('splittingLineId', null);

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and(JournalEntry::count())->toBe(1);
});

it('bulk-categorizes selected lines in one pass', function () {
    $a = reviewLine(10000);
    $b = reviewLine(20000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('selected', [$a->id, $b->id])
        ->set('bulkCategory', $this->income->id)
        ->call('bulkCategorize')
        ->assertHasNoErrors();

    expect(JournalEntry::count())->toBe(2)
        ->and($a->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($b->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('rolls the whole bulk batch back when one line is in a locked period', function () {
    $this->company->update(['lock_date' => '2026-06-15']);
    $locked = reviewLine(10000, date: '2026-06-10'); // on/before the lock date
    $ok = reviewLine(20000, date: '2026-06-20');

    expect(fn () => app(BulkCategorizeStatementLines::class)->handle([$locked->id, $ok->id], $this->income->id))
        ->toThrow(PeriodLockedException::class);

    expect(JournalEntry::count())->toBe(0)
        ->and($locked->fresh()->created_journal_entry_id)->toBeNull()
        ->and($ok->fresh()->created_journal_entry_id)->toBeNull();
});

it('bulk-excludes reviewable lines and re-includes excluded ones', function () {
    $a = reviewLine(10000);
    $b = reviewLine(20000);
    $bulk = app(BulkSetStatementLineStatus::class);

    expect($bulk->exclude([$a->id, $b->id]))->toBe(2)
        ->and($a->fresh()->match_status)->toBe(StatementLineMatchStatus::Ignored);

    expect($bulk->include([$a->id]))->toBe(1)
        ->and($a->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched);
});

function cardLine(int $amount, string $date): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->card->id,
        'txn_date' => $date,
        'amount_cents' => $amount,
        'description' => 'CARD '.abs($amount),
        'match_status' => 'unmatched',
        'created_journal_entry_id' => null,
    ]);
}

it('suggests and records an inter-account transfer pair', function () {
    $out = reviewLine(-50000, date: '2026-06-10'); // money out of the bank
    $in = cardLine(50000, '2026-06-11');            // arriving on the card, next day

    $page = Livewire::test('pages::banking.review', ['company' => $this->company]);
    expect($page->instance()->transferCandidates)->toHaveCount(1);

    $page->call('recordTransfer', $out->id, $in->id)->assertHasNoErrors();

    expect($out->fresh()->match_status)->toBe(StatementLineMatchStatus::Matched)
        ->and($out->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and($in->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and(Transfer::count())->toBe(1);

    // Both legs left the feed.
    expect(substr_count($page->html(), 'data-test="review-row"'))->toBe(0);
});

it('does not pair unequal amounts or out-of-window dates', function () {
    reviewLine(-50000, date: '2026-06-10');
    cardLine(49999, '2026-06-11'); // unequal

    reviewLine(-30000, date: '2026-06-01');
    cardLine(30000, '2026-06-20'); // 19 days apart, outside the window

    $page = Livewire::test('pages::banking.review', ['company' => $this->company]);
    expect($page->instance()->transferCandidates)->toHaveCount(0);
});

it('bulk categorize can name a vendor, recording outflows as expenses to them', function () {
    $vendor = Contact::factory()->vendor()->create();
    $out = reviewLine(-7000);
    $in = reviewLine(3000);

    $count = app(BulkCategorizeStatementLines::class)->handle([$out->id, $in->id], $this->expense->id, $vendor->id);

    expect($count)->toBe(2)
        ->and(Expense::query()->where('payee_contact_id', $vendor->id)->count())->toBe(1)
        ->and($out->fresh()->createdJournalEntry->source_type)->toBe(Expense::class)
        ->and($in->fresh()->createdJournalEntry->source_type)->toBe(BankStatementImport::class)
        ->and($in->fresh()->createdJournalEntry->lines()->where('account_id', $this->expense->id)->value('contact_id'))->toBe($vendor->id);
});
