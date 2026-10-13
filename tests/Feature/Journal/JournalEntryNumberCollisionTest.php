<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Posting\EntryNumberGenerator;
use Livewire\Livewire;

/**
 * The New journal entry page shows the next JE number when it opens, but every
 * posting in the company draws from the same sequence. Someone else posting
 * before Post is clicked must not turn the save into a duplicate-number 500.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A balanced two-line entry saved through the same Action the form uses,
 * numbered from the shared sequence unless $entryNo is given.
 */
function otherJournalEntry(Account $debit, Account $credit, ?string $entryNo = null): JournalEntry
{
    return app(SaveJournalEntry::class)->handle([
        'entry_no' => $entryNo,
        'entry_date' => '2026-08-01',
        'memo' => 'Posted elsewhere',
        'lines' => [
            ['account_id' => $debit->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);
}

function fillJournalForm(mixed $component, Account $debit, Account $credit): mixed
{
    return $component
        ->set('lines.0.account_id', $debit->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $credit->id)
        ->set('lines.1.credit', '100.00');
}

it('takes the next free number at save when another posting used the suggested one', function () {
    $component = Livewire::test('pages::journal.form', ['company' => $this->company]);
    $suggested = $component->get('entryNo');

    // Another user posts while this page is open, taking the suggested number.
    $other = otherJournalEntry($this->expense, $this->bank);
    expect($other->entry_no)->toBe($suggested);

    fillJournalForm($component, $this->expense, $this->bank)
        ->call('postEntry')
        ->assertHasNoErrors();

    $mine = JournalEntry::query()->whereKeyNot($other->id)->sole();

    expect($mine->isPosted())->toBeTrue()
        ->and($mine->entry_no)->not->toBe($suggested)
        ->and($mine->entry_no)->toBe(sprintf('JE-%06d', (int) substr($suggested, 3) + 1));
});

it('also takes a fresh number when the suggested one is saved as a draft', function () {
    $component = Livewire::test('pages::journal.form', ['company' => $this->company]);
    $suggested = $component->get('entryNo');

    otherJournalEntry($this->expense, $this->bank);

    fillJournalForm($component, $this->expense, $this->bank)
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalEntry::query()->where('entry_no', $suggested)->count())->toBe(1);
});

it('rejects a typed number that is already used with a message, not a 500', function () {
    otherJournalEntry($this->expense, $this->bank, 'ADJ-0042');

    fillJournalForm(Livewire::test('pages::journal.form', ['company' => $this->company]), $this->expense, $this->bank)
        ->set('entryNo', 'ADJ-0042')
        ->call('postEntry')
        ->assertHasErrors(['entryNo' => 'unique']);

    expect(JournalEntry::query()->count())->toBe(1);
});

it('keeps a typed number that is free', function () {
    fillJournalForm(Livewire::test('pages::journal.form', ['company' => $this->company]), $this->expense, $this->bank)
        ->set('entryNo', 'ADJ-2026-01')
        ->call('postEntry')
        ->assertHasNoErrors();

    expect(JournalEntry::query()->sole()->entry_no)->toBe('ADJ-2026-01');
});

it('lets an entry keep its own number when it is edited', function () {
    $entry = otherJournalEntry($this->expense, $this->bank);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->set('memo', 'Edited')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($entry->fresh()->entry_no)->toBe($entry->entry_no)
        ->and($entry->fresh()->memo)->toBe('Edited');
});

it('never hands out a number that is already taken', function () {
    // JE-000006 is created first; a later entry is typed in by hand below it,
    // so the newest entry by id no longer holds the highest number.
    otherJournalEntry($this->expense, $this->bank, 'JE-000006');
    otherJournalEntry($this->expense, $this->bank, 'JE-000005');

    expect(app(EntryNumberGenerator::class)->next($this->company))->toBe('JE-000007');

    // The postings that share the sequence get the same protection.
    expect(otherJournalEntry($this->expense, $this->bank)->entry_no)->toBe('JE-000007');
});
