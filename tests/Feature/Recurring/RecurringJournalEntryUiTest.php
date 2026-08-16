<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\RecurringJournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

it('creates a memorized journal entry through the form', function () {
    Livewire::test('pages::recurring-journal.form', ['company' => $this->company])
        ->set('name', 'Monthly depreciation')
        ->set('frequency', 'monthly')
        ->set('start_date', '2026-05-24')
        ->set('day_of_month', 24)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.debit', '50.00')
        ->set('lines.1.account_id', $this->bank->id)
        ->set('lines.1.credit', '50.00')
        ->call('save')
        ->assertHasNoErrors();

    $schedule = RecurringJournalEntry::query()->firstOrFail();
    expect($schedule->lines)->toHaveCount(2)
        ->and($schedule->next_run_date->toDateString())->toBe('2026-05-24');
});

it('rejects an unbalanced template', function () {
    Livewire::test('pages::recurring-journal.form', ['company' => $this->company])
        ->set('frequency', 'monthly')
        ->set('start_date', '2026-05-24')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.debit', '50.00')
        ->set('lines.1.account_id', $this->bank->id)
        ->set('lines.1.credit', '40.00')
        ->call('save')
        ->assertHasErrors('lines');

    expect(RecurringJournalEntry::query()->count())->toBe(0);
});

it('generates a draft on demand and pauses/resumes', function () {
    $schedule = RecurringJournalEntry::create([
        'name' => 'Rent accrual',
        'frequency' => 'monthly',
        'start_date' => '2026-05-24',
        'day_of_month' => 24,
        'end_type' => 'never',
        'next_run_date' => '2026-05-24',
        'is_active' => true,
        'occurrences_generated' => 0,
    ]);
    $schedule->lines()->create(['company_id' => $this->company->id, 'account_id' => $this->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'line_order' => 0]);
    $schedule->lines()->create(['company_id' => $this->company->id, 'account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'line_order' => 1]);

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('generateNow');

    expect(JournalEntry::query()->where('recurring_journal_entry_id', $schedule->id)->count())->toBe(1);

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('pauseSchedule');
    expect($schedule->fresh()->is_active)->toBeFalse();

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->call('resumeSchedule');
    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('memorizes from an existing journal entry via the from query param', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-000050', 'entry_date' => '2026-05-24', 'memo' => 'Source entry']);
    $entry->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 7500, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 7500, 'line_order' => 1]);

    $component = Livewire::withQueryParams(['from' => $entry->id])
        ->test('pages::recurring-journal.form', ['company' => $this->company]);

    $lines = $component->get('lines');
    expect($component->get('memo'))->toBe('Source entry')
        ->and($lines)->toHaveCount(2)
        ->and((int) $lines[0]['account_id'])->toBe($this->expense->id)
        ->and($lines[0]['debit'])->toBe('75.00');
});

it('shows a manually paused memorized entry as Paused, not Ended', function () {
    // Regression guard: same "Ended" badge bug as recurring documents — a paused
    // memorized entry is still resumable and must not read as finished.
    $schedule = RecurringJournalEntry::create([
        'name' => 'Rent accrual',
        'frequency' => 'monthly',
        'start_date' => '2026-05-24',
        'day_of_month' => 24,
        'end_type' => 'never',
        'next_run_date' => '2026-05-24',
        'is_active' => true,
        'occurrences_generated' => 0,
    ]);
    $schedule->lines()->create(['company_id' => $this->company->id, 'account_id' => $this->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'line_order' => 0]);
    $schedule->lines()->create(['company_id' => $this->company->id, 'account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'line_order' => 1]);

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('pauseSchedule');

    $schedule->refresh();
    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->hasEnded())->toBeFalse();

    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule])
        ->assertOk()
        ->assertSee('Paused')
        ->assertDontSee('Ended')
        ->assertSee('Resume');

    // A memorized entry that has genuinely run out still reads "Ended".
    $schedule->update(['next_run_date' => null]);
    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->assertOk()
        ->assertSee('Ended');
});
