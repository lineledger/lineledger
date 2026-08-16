<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
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

function makePostedEntry(int $cents = 10000): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-SRC-1',
        'entry_date' => now()->subDays(3)->toDateString(),
        'memo' => 'Cost allocation',
    ]);

    $entry->lines()->createMany([
        ['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1],
    ]);

    app(JournalPoster::class)->post($entry);

    return $entry->fresh('lines');
}

it('prefills a new entry from a source entry with a fresh number and today\'s date', function () {
    $source = makePostedEntry();

    $component = Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::journal.form', ['company' => $this->company]);

    $component
        ->assertSet('entry', null)
        ->assertSet('memo', 'Cost allocation')
        ->assertSet('entryDate', $this->company->currentDateTime()->toDateString())
        ->assertCount('lines', 2)
        ->assertSet('lines.0.account_id', $this->bank->id)
        ->assertSet('lines.0.debit', '100.00')
        ->assertSet('lines.1.account_id', $this->income->id)
        ->assertSet('lines.1.credit', '100.00');

    expect($component->get('entryNo'))->not->toBe('JE-SRC-1');
});

it('saves the duplicate as a new entry without touching the source', function () {
    $source = makePostedEntry();

    Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::journal.form', ['company' => $this->company])
        ->call('postEntry');

    $entries = JournalEntry::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2);

    $duplicate = $entries->last();

    expect($duplicate->id)->not->toBe($source->id)
        ->and($duplicate->entry_no)->not->toBe('JE-SRC-1')
        ->and($duplicate->lines)->toHaveCount(2);

    $source->refresh();
    expect($source->entry_no)->toBe('JE-SRC-1')
        ->and($source->lines)->toHaveCount(2);
});

it('ignores from= when the source entry belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);

    $otherEntry = JournalEntry::create([
        'entry_no' => 'JE-OTHER-1',
        'entry_date' => now()->toDateString(),
        'memo' => 'Other co entry',
    ]);

    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['from' => $otherEntry->id])
        ->test('pages::journal.form', ['company' => $this->company])
        ->assertSet('memo', '')
        ->assertSet('lines.0.account_id', null);
});

it('edits a posted entry in place and recomputes balances', function () {
    $entry = makePostedEntry(10000);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('isPosted', true)
        ->set('lines.0.debit', '200.00')
        ->set('lines.1.credit', '200.00')
        ->call('saveChanges')
        ->assertHasNoErrors()
        ->assertRedirect();

    $entry->refresh();

    expect($entry->is_posted)->toBeTrue()
        ->and($entry->totalDebitsCents())->toBe(20000)
        ->and($entry->totalCreditsCents())->toBe(20000);

    expect($this->bank->fresh()->balance_cents)->toBe(20000)
        ->and($this->income->fresh()->balance_cents)->toBe(20000);
});

it('refuses to save a posted entry that no longer balances', function () {
    $entry = makePostedEntry(10000);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->set('lines.0.debit', '250.00')
        ->call('saveChanges')
        ->assertHasErrors('lines');

    $entry->refresh();

    expect($entry->totalDebitsCents())->toBe(10000)
        ->and($entry->totalCreditsCents())->toBe(10000);
});
