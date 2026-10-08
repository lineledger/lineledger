<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Company;
use App\Models\EditLock;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\RecurringJournalEntry;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->locks = app(EditLockManager::class);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $lines = [
        ['account_id' => $this->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'memo' => null],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'memo' => null],
    ];

    $this->entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-06-01',
        'memo' => 'Original memo',
        'lines' => $lines,
    ]);

    $this->template = app(SaveJournalEntryTemplate::class)->handle([
        'name' => 'Original template',
        'is_active' => true,
        'lines' => $lines,
    ]);

    $this->recurring = app(SaveRecurringJournalEntry::class)->handle([
        'name' => 'Original schedule',
        'memo' => null,
        'frequency' => RecurrenceFrequency::Monthly->value,
        'start_date' => '2026-06-01',
        'day_of_month' => 1,
        'day_anchor' => RecurrenceDayAnchor::DayOfMonth->value,
        'end_type' => RecurrenceEndType::Never->value,
        'lines' => $lines,
    ]);

    $this->records = [
        'entry' => $this->entry,
        'journalEntryTemplate' => $this->template,
        'recurring' => $this->recurring,
    ];
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

$forms = [
    'journal entry' => ['pages::journal.form', 'entry', 'saveDraft', 'memo'],
    'journal entry template' => ['pages::journal-entry-templates.form', 'journalEntryTemplate', 'save', 'name'],
    'recurring journal entry' => ['pages::recurring-journal.form', 'recurring', 'save', 'name'],
];

it('takes the lock when the record is opened for editing', function (string $component, string $param) {
    $this->actingAs($this->jane);

    $form = Livewire::test($component, ['company' => $this->company, $param => $this->records[$param]])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with($forms);

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $component, string $param, string $save, string $field) {
    $record = $this->records[$param];
    $before = $record->fresh()->{$field};

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record]);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSet('editLockBlocked', true)
        ->assertSet('editLockToken', null)
        ->assertSee($this->jane->name)
        ->set($field, 'Bob was here')
        ->call($save)
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($record->fresh()->{$field})->toBe($before);
})->with($forms);

it('takes no lock on a source-linked journal entry, whose form redirects to its document', function () {
    $invoice = editLockDraftInvoice($this->company);
    app(InvoicePoster::class)->post($invoice);
    $linked = JournalEntry::query()->whereNotNull('source_type')->sole();

    $this->actingAs($this->jane);
    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $linked])
        ->assertRedirect()
        ->assertSet('editLockToken', null);

    expect(EditLock::query()->count())->toBe(0);
});

it('refuses voiding or reversing a journal entry someone is editing', function () {
    $posted = app(JournalPoster::class)->post($this->entry);
    $this->locks->acquire($posted, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $posted->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'journal entry']))
        ->call('void')
        ->assertDispatched('toast-show')
        ->call('reverse')
        ->assertNoRedirect();

    expect($posted->fresh()->isVoided())->toBeFalse()
        ->and(JournalEntry::query()->where('reverses_entry_id', $posted->id)->exists())->toBeFalse();
});

it('refuses deleting a draft journal entry someone is editing', function () {
    $this->locks->acquire($this->entry, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $this->entry->fresh()])
        ->call('deleteDraft')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect(JournalEntry::query()->whereKey($this->entry->id)->exists())->toBeTrue();
});

it('refuses pausing or deleting a recurring journal entry someone is editing', function () {
    $this->locks->acquire($this->recurring, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $this->recurring->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'recurring journal entry']))
        ->call('pauseSchedule')
        ->assertDispatched('toast-show')
        ->call('deleteSchedule')
        ->assertNoRedirect();

    expect(RecurringJournalEntry::query()->whereKey($this->recurring->id)->exists())->toBeTrue()
        ->and($this->recurring->fresh()->is_active)->toBeTrue();
});

it('pauses a recurring journal entry nobody is editing', function () {
    $this->actingAs($this->bob);
    Livewire::test('pages::recurring-journal.show', ['company' => $this->company, 'recurring' => $this->recurring])
        ->call('pauseSchedule');

    expect($this->recurring->fresh()->is_active)->toBeFalse();
});

it('guards exactly the show page methods that change the record', function (string $component, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with([
    'journal entry' => ['pages::journal.show', ['deleteDraft', 'reverse', 'void']],
    'recurring journal entry' => ['pages::recurring-journal.show', ['deleteSchedule', 'generateNow', 'pauseSchedule', 'resumeSchedule']],
]);

it('refuses deleting a journal entry template someone is editing', function () {
    $this->actingAs($this->jane);
    Livewire::test('pages::journal-entry-templates.form', ['company' => $this->company, 'journalEntryTemplate' => $this->template]);

    $this->actingAs($this->bob);
    Livewire::test('pages::journal-entry-templates.index', ['company' => $this->company])
        ->call('delete', $this->template->id)
        ->assertDispatched('toast-show');

    expect(JournalEntryTemplate::query()->whereKey($this->template->id)->exists())->toBeTrue();
});

it('deletes a journal entry template nobody is editing', function () {
    $this->actingAs($this->bob);
    Livewire::test('pages::journal-entry-templates.index', ['company' => $this->company])
        ->call('delete', $this->template->id);

    expect(JournalEntryTemplate::query()->whereKey($this->template->id)->exists())->toBeFalse();
});
