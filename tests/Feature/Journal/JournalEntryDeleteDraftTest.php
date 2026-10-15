<?php

use App\Actions\Accounting\DeleteDraftJournalEntry;
use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $this->draft = app(SaveJournalEntry::class)->handle([
        'entry_date' => now()->toDateString(),
        'memo' => 'Monthly insurance',
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 833333, 'credit_cents' => 0],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 833333],
        ],
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('offers Delete draft on a manual draft entry', function () {
    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->draft])
        ->assertSeeHtml('data-test="delete-entry-button"')
        ->assertDontSeeHtml('data-test="void-entry-button"');
});

it('offers Void, not Delete draft, on a posted entry', function () {
    $posted = app(JournalPoster::class)->post($this->draft);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $posted->fresh()])
        ->assertSeeHtml('data-test="void-entry-button"')
        ->assertDontSeeHtml('data-test="delete-entry-button"');
});

it('does not offer Delete draft on a source-linked draft', function () {
    $deposit = Deposit::create([
        'bank_account_id' => $this->draft->lines->last()->account_id,
        'deposit_no' => 'DEP-DRAFT-1',
        'deposit_date' => now()->toDateString(),
    ]);
    $this->draft->update(['source_type' => Deposit::class, 'source_id' => $deposit->id]);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->draft->fresh()])
        ->assertDontSeeHtml('data-test="delete-entry-button"');
});

it('deletes a draft and its lines, then returns to the journal', function () {
    $lineIds = $this->draft->lines->pluck('id')->all();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->draft])
        ->call('deleteDraft')
        ->assertDispatched('toast-show')
        ->assertRedirect(route('journal.index', ['company' => $this->company->slug]));

    expect(JournalEntry::query()->whereKey($this->draft->id)->exists())->toBeFalse()
        ->and(JournalLine::query()->whereIn('id', $lineIds)->exists())->toBeFalse();
});

it('records the deletion of the entry and each line in the audit trail', function () {
    $entryId = $this->draft->id;
    $lineIds = $this->draft->lines->pluck('id')->sort()->values()->all();

    app(DeleteDraftJournalEntry::class)->handle($this->draft);

    $deletedLineIds = AccountingAuditLog::query()
        ->where('action', AuditAction::JournalLineDeleted->value)
        ->pluck('auditable_id')
        ->sort()->values()->all();

    expect(AccountingAuditLog::query()
        ->where('action', AuditAction::JournalEntryDeleted->value)
        ->where('auditable_id', $entryId)
        ->exists())->toBeTrue()
        ->and($deletedLineIds)->toBe($lineIds);
});

it('refuses to delete a posted entry, leaving it and the ledger untouched', function () {
    $posted = app(JournalPoster::class)->post($this->draft);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $posted->fresh()])
        ->call('deleteDraft')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect(JournalEntry::query()->whereKey($posted->id)->exists())->toBeTrue()
        ->and(JournalLine::query()->where('journal_entry_id', $posted->id)->count())->toBe(2);
});

it('refuses to delete an entry that was posted after the page loaded', function () {
    $page = Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->draft]);

    app(JournalPoster::class)->post($this->draft->fresh());

    $page->call('deleteDraft')->assertNoRedirect();

    expect(JournalEntry::query()->whereKey($this->draft->id)->exists())->toBeTrue();
});

it('refuses posted and source-linked entries at the action', function () {
    $linked = app(SaveJournalEntry::class)->handle([
        'entry_date' => now()->toDateString(),
        'lines' => $this->draft->lines->map->only('account_id', 'debit_cents', 'credit_cents')->all(),
    ]);
    $linked->update(['source_type' => Deposit::class, 'source_id' => 1]);

    expect(fn () => app(DeleteDraftJournalEntry::class)->handle($linked))
        ->toThrow(LinkedJournalEntryException::class);

    $posted = app(JournalPoster::class)->post($this->draft);

    expect(fn () => app(DeleteDraftJournalEntry::class)->handle($posted))
        ->toThrow(PostedDocumentDeletionException::class);
});
