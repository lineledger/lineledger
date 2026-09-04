<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\UpdateJournalEntryHeader;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Reconciliation\BankReconciliationService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->fees = Account::query()->where('code', '6010')->first(); // Bank Charges

    $this->rec = app(BankReconciliationService::class)->begin(
        $this->bank,
        Carbon::parse('2026-09-30'),
        0,
        ['cents' => 600, 'date' => Carbon::parse('2026-09-30'), 'account_id' => $this->fees->id],
    );

    $this->entry = $this->rec->serviceChargeEntry()->firstOrFail()->load('lines');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('offers Edit alongside the reconciliation link on the show page', function () {
    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->entry])
        ->assertSeeHtml('data-test="edit-entry-button"')
        ->assertSeeHtml('data-test="view-source-button"')
        ->assertSee('Bank reconciliation')
        ->assertDontSeeHtml('data-test="void-entry-button"');
});

it('links an in-progress reconciliation to the reconcile screen for its bank account', function () {
    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->entry])
        ->assertSet('sourceUrl', route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));
});

it('opens the edit form in header-only mode with the lines locked', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $this->entry])
        ->assertNoRedirect()
        ->assertSet('linesLocked', true)
        ->assertSet('isPosted', true)
        ->assertSeeHtml('data-test="locked-lines"')
        ->assertSeeHtml('data-test="locked-lines-callout"')
        ->assertDontSeeHtml('data-test="entry-line-row"');
});

it('changes the date and memo in place, keeps the cleared bank line, and updates the reconciliation', function () {
    $bankLine = $this->entry->lines->firstWhere('account_id', $this->bank->id);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $this->entry])
        ->set('entryDate', '2026-09-15')
        ->set('memo', 'Monthly account fee')
        ->call('saveChanges')
        ->assertHasNoErrors()
        ->assertRedirect(route('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id]));

    $fresh = $this->entry->fresh('lines');
    $freshBankLine = $fresh->lines->firstWhere('account_id', $this->bank->id);

    expect($fresh->entry_date->toDateString())->toBe('2026-09-15')
        ->and($fresh->memo)->toBe('Monthly account fee')
        ->and($fresh->isPosted())->toBeTrue()
        ->and($fresh->lines)->toHaveCount(2)
        ->and($freshBankLine->id)->toBe($bankLine->id)
        ->and($freshBankLine->cleared_at)->not->toBeNull()
        ->and($freshBankLine->bank_reconciliation_id)->toBe($this->rec->id)
        ->and($freshBankLine->entry_date->toDateString())->toBe('2026-09-15')
        ->and($freshBankLine->credit_cents)->toBe(600)
        ->and($this->rec->fresh()->markedLineIds())->toContain($bankLine->id)
        ->and($this->rec->fresh()->service_charge_date->toDateString())->toBe('2026-09-15');
});

it('refuses to move the entry once its reconciliation is completed', function () {
    $service = app(BankReconciliationService::class);
    $this->rec->forceFill(['ending_balance_cents' => -600])->save();
    $service->complete($this->rec->fresh());

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $this->entry->fresh()])
        ->set('entryDate', '2026-09-15')
        ->call('saveChanges')
        ->assertHasErrors('entryDate')
        ->assertNoRedirect();

    expect($this->entry->fresh()->entry_date->toDateString())->toBe('2026-09-30');
});

it('still refuses a full line rebuild through the save action', function () {
    $save = fn () => app(SaveJournalEntry::class)->handle([
        'entry_no' => $this->entry->entry_no,
        'entry_date' => '2026-09-15',
        'memo' => 'tampered',
        'lines' => [
            ['account_id' => $this->fees->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $this->entry);

    expect($save)->toThrow(LinkedJournalEntryException::class);
});

it('rejects header edits on other source-linked entries', function () {
    $deposit = Deposit::create([
        'bank_account_id' => $this->bank->id,
        'deposit_no' => 'DEP-HDR-1',
        'deposit_date' => now()->toDateString(),
    ]);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-DEP-1',
        'entry_date' => now()->toDateString(),
        'memo' => 'Deposit',
        'source_type' => Deposit::class,
        'source_id' => $deposit->id,
    ]);

    $update = fn () => app(UpdateJournalEntryHeader::class)->handle($entry, [
        'entry_date' => now()->subDay()->toDateString(),
        'memo' => 'moved',
    ]);

    expect($update)->toThrow(LinkedJournalEntryException::class);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertRedirect(route('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id]));
});
