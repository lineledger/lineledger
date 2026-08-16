<?php

use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\FundType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();

    $this->today = $this->company->currentDateTime()->toDateString();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function makeJournalSchedule(array $overrides = []): RecurringJournalEntry
{
    return app(SaveRecurringJournalEntry::class)->handle(array_merge([
        'name' => 'Monthly depreciation',
        'memo' => 'Depreciation',
        'frequency' => 'monthly',
        'start_date' => test()->today,
        'day_of_month' => (int) test()->company->currentDateTime()->format('j'),
        'end_type' => 'never',
        'lines' => [
            ['account_id' => test()->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => test()->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $overrides));
}

it('generates a balanced draft journal entry, never posting', function () {
    $schedule = makeJournalSchedule();

    $created = app(RecurringJournalEntryGenerator::class)
        ->generateDue($schedule, CarbonImmutable::parse($this->today));

    expect($created)->toHaveCount(1);

    $entry = $created->first();
    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->is_posted)->toBeFalse()
        ->and($entry->recurring_journal_entry_id)->toBe($schedule->id)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebitsCents())->toBe(5000);

    expect($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('catches up missed occurrences up to today', function () {
    // Start three months ago; generating "today" should produce 3 monthly drafts.
    $schedule = makeJournalSchedule(['start_date' => '2026-02-24', 'day_of_month' => 24]);

    $created = app(RecurringJournalEntryGenerator::class)
        ->generateDue($schedule, CarbonImmutable::parse('2026-05-24'));

    expect($created->count())->toBe(4); // Feb, Mar, Apr, May
    expect(JournalEntry::query()->where('recurring_journal_entry_id', $schedule->id)->count())->toBe(4);
});

it('stops after the configured number of occurrences', function () {
    $schedule = makeJournalSchedule([
        'start_date' => '2026-01-15',
        'day_of_month' => 15,
        'end_type' => 'after_occurrences',
        'max_occurrences' => 2,
    ]);

    app(RecurringJournalEntryGenerator::class)
        ->generateDue($schedule, CarbonImmutable::parse('2026-05-15'));

    $fresh = $schedule->fresh();
    expect($fresh->occurrences_generated)->toBe(2)
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->next_run_date)->toBeNull();
});

it('pauses when a line account no longer exists', function () {
    $schedule = makeJournalSchedule();
    $this->expense->delete(); // soft-delete: the line account now reads as gone

    app(RecurringJournalEntryGenerator::class)
        ->generateDue($schedule, CarbonImmutable::parse($this->today));

    $fresh = $schedule->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->paused_reason)->not->toBeNull();
    expect(JournalEntry::query()->where('recurring_journal_entry_id', $schedule->id)->count())->toBe(0);
});

it('generates one occurrence on demand', function () {
    $schedule = makeJournalSchedule();

    $entry = app(RecurringJournalEntryGenerator::class)->generateOne($schedule);

    expect($entry->is_posted)->toBeFalse()
        ->and($entry->recurring_journal_entry_id)->toBe($schedule->id);
    expect($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('carries the fund dimension from the template into the generated draft', function () {
    // Regression guard: the template save and the draft generator both copied
    // class_id/location_id but dropped fund_id, so recurring entries lost their fund.
    $fund = Fund::create(['name' => 'Building Fund', 'fund_type' => FundType::Restricted, 'is_default' => false, 'is_active' => true]);

    $schedule = makeJournalSchedule(['lines' => [
        ['account_id' => $this->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'fund_id' => $fund->id],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'fund_id' => $fund->id],
    ]]);

    // The template line stores the fund...
    expect($schedule->lines->pluck('fund_id')->unique()->values()->all())->toBe([$fund->id]);

    // ...and the generated draft carries it end-to-end.
    $entry = app(RecurringJournalEntryGenerator::class)->generateOne($schedule);

    expect($entry->lines->pluck('fund_id')->unique()->values()->all())->toBe([$fund->id]);
});
