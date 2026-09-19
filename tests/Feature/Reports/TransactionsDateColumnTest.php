<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Livewire\Livewire;

/*
| The Date column and every export printed a posted line's date as a Carbon
| string ("2026-05-01 00:00:00"). journal_lines.entry_date is a plain date, so
| the report shows and exports it as one.
*/

beforeEach(function () {
    $user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($user);

    $account = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $entry = JournalEntry::create(['entry_no' => 'JE-DATE-1', 'entry_date' => '2026-05-01', 'memo' => 'DATE-MEMO', 'is_posted' => true]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit_cents' => 4500,
        'credit_cents' => 0,
        'entry_date' => '2026-05-01',
        'is_posted' => true,
        'memo' => 'DATE-MEMO',
    ]);

    $this->report = fn () => Livewire::withQueryParams(['start' => '2026-01-01', 'end' => '2026-12-31'])
        ->test('pages::reports.transactions', ['company' => $this->company]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('shows each line\'s date without a time', function () {
    ($this->report)()
        ->assertOk()
        ->assertSee('2026-05-01')
        ->assertDontSee('00:00:00');
});

it('exports each line\'s date without a time', function () {
    $response = ($this->report)()->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('2026-05-01')
        ->and($csv)->not->toContain('00:00:00');
});
