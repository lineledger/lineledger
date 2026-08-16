<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeManualPostedEntry(int $cents = 10000): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-MANUAL-1',
        'entry_date' => now()->subDays(3)->toDateString(),
        'memo' => 'Manual entry',
    ]);

    $entry->lines()->createMany([
        ['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1],
    ]);

    app(JournalPoster::class)->post($entry);

    return $entry->fresh('lines');
}

function makeSourceLinkedEntry(): array
{
    $deposit = Deposit::create([
        'bank_account_id' => test()->bank->id,
        'deposit_no' => 'DEP-LOCK-1',
        'deposit_date' => now()->toDateString(),
    ]);

    $entry = makeManualPostedEntry();
    $entry->update(['source_type' => Deposit::class, 'source_id' => $deposit->id]);

    return [$entry->fresh('lines'), $deposit];
}

it('redirects the edit form for a source-linked entry back to its source record', function () {
    [$entry, $deposit] = makeSourceLinkedEntry();

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertRedirect(route('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id]));
});

it('still loads the edit form for a manual entry', function () {
    $entry = makeManualPostedEntry();

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertNoRedirect()
        ->assertSet('isPosted', true)
        ->assertSet('memo', 'Manual entry');
});

it('shows a View source link and hides Edit/Void/Reverse for a source-linked entry', function () {
    [$entry] = makeSourceLinkedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSeeHtml('data-test="view-source-button"')
        ->assertDontSeeHtml('data-test="edit-entry-button"')
        ->assertDontSeeHtml('data-test="void-entry-button"')
        ->assertDontSeeHtml('data-test="reverse-entry-button"');
});

it('duplicates the source deposit (not a manual JE) for a deposit-linked entry', function () {
    [$entry, $deposit] = makeSourceLinkedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('duplicateUrl', route('deposits.create', ['company' => $this->company->slug, 'from' => $deposit->id]))
        ->assertSeeHtml('data-test="duplicate-entry-button"');
});

it('duplicates the journal entry itself for a manual entry', function () {
    $entry = makeManualPostedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('duplicateUrl', route('journal.create', ['company' => $this->company->slug, 'from' => $entry->id]))
        ->assertSeeHtml('data-test="duplicate-entry-button"');
});

it('hides Duplicate for a source-linked entry whose document has no duplicate flow', function () {
    $entry = makeManualPostedEntry();
    $entry->update(['source_type' => Invoice::class, 'source_id' => 999]);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry->fresh()])
        ->assertSet('duplicateUrl', null)
        ->assertDontSeeHtml('data-test="duplicate-entry-button"');
});

it('shows Edit for a manual entry on the show page', function () {
    $entry = makeManualPostedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->assertSeeHtml('data-test="edit-entry-button"')
        ->assertDontSeeHtml('data-test="view-source-button"');
});

it('refuses to void a source-linked entry from the journal', function () {
    [$entry] = makeSourceLinkedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->call('void')
        ->assertStatus(403);
});

it('refuses to reverse a source-linked entry from the journal', function () {
    [$entry] = makeSourceLinkedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $entry])
        ->call('reverse')
        ->assertStatus(403);
});

it('throws when the save action is handed a source-linked entry', function () {
    [$entry] = makeSourceLinkedEntry();

    $save = fn () => app(SaveJournalEntry::class)->handle([
        'entry_no' => $entry->entry_no,
        'entry_date' => $entry->entry_date->toDateString(),
        'memo' => 'tampered',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $entry);

    expect($save)->toThrow(LinkedJournalEntryException::class);
});

it('still saves a manual entry through the save action', function () {
    $entry = makeManualPostedEntry();

    $updated = app(SaveJournalEntry::class)->handle([
        'entry_no' => $entry->entry_no,
        'entry_date' => $entry->entry_date->toDateString(),
        'memo' => 'still editable',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 7000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 7000],
        ],
    ], $entry);

    expect($updated->memo)->toBe('still editable')
        ->and($updated->totalDebitsCents())->toBe(7000);
});
