<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MemorizedReport;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function columnToggleLine(): void
{
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-COL-1', 'entry_date' => '2026-05-01', 'memo' => 'Toggle memo', 'is_posted' => true]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $income->id,
        'debit_cents' => 0,
        'credit_cents' => 5000,
        'entry_date' => '2026-05-01',
        'is_posted' => true,
    ]);
}

it('shows every column by default on the transactions report', function () {
    columnToggleLine();

    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Entry #</th>')
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Name</th>')
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Memo</th>')
        ->assertSeeHtml('data-test="column-picker"');
});

it('hides the requested columns via ?hide=memo,entry_no', function () {
    columnToggleLine();

    Livewire::withQueryParams(['hide' => 'memo,entry_no'])
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Entry #</th>')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Memo</th>')
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Name</th>');
});

it('round-trips a column through toggleColumn', function () {
    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->call('toggleColumn', 'memo')
        ->assertSet('hiddenColumns', 'memo')
        ->call('toggleColumn', 'entry_no')
        ->assertSet('hiddenColumns', 'memo,entry_no')
        ->call('toggleColumn', 'memo')
        ->assertSet('hiddenColumns', 'entry_no')
        ->call('toggleColumn', 'entry_no')
        ->assertSet('hiddenColumns', '');
});

it('silently ignores unknown column keys', function () {
    $component = Livewire::withQueryParams(['hide' => 'bogus,memo'])
        ->test('pages::reports.transactions', ['company' => $this->company]);

    expect($component->instance()->hiddenColumnKeys())->toBe(['memo'])
        ->and($component->instance()->columnVisible('memo'))->toBeFalse()
        ->and($component->instance()->visibleColumnCount(3))->toBe(5);

    // Toggling an unregistered key is a no-op.
    $component->call('toggleColumn', 'bogus')->assertSet('hiddenColumns', 'bogus,memo');
});

it('memorizes and restores hidden columns', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->set('hiddenColumns', 'memo,name')
        ->set('memorizeName', 'Slim transactions')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $user->id)->first();

    expect($memorized->settings['hiddenColumns'])->toBe('memo,name');

    Livewire::actingAs($user)
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('hiddenColumns', 'memo,name');
});

it('keeps every column in the CSV export while columns are hidden on screen', function () {
    columnToggleLine();

    $response = Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('hiddenColumns', 'memo,entry_no,name')
        ->instance()
        ->exportCsv();

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Date,"Entry #",Account,Name,Memo,Debit,Credit')
        ->and($csv)->toContain('Toggle memo');
});

it('hides a bucket column on the AR aging summary', function () {
    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertSeeHtml('data-test="sort-b1_30"')
        ->assertSeeHtml('data-test="column-picker"');

    Livewire::withQueryParams(['hide' => 'b1_30'])
        ->test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="sort-b1_30"')
        ->assertSeeHtml('data-test="sort-current"')
        ->assertSeeHtml('data-test="sort-b31_60"');
});

it('hides a bucket column on the AP aging summary', function () {
    Livewire::withQueryParams(['hide' => 'b90_plus'])
        ->test('pages::reports.ap-aging', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="sort-b90_plus"')
        ->assertSeeHtml('data-test="sort-current"');
});

it('hides the due, total and paid columns on open invoices', function () {
    Livewire::withQueryParams(['hide' => 'due_date,total,paid'])
        ->test('pages::reports.open-invoices', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="sort-due_date"')
        ->assertDontSeeHtml('data-test="sort-total"')
        ->assertDontSeeHtml('data-test="sort-paid"')
        ->assertSeeHtml('data-test="sort-balance"');
});

it('hides the due, total and paid columns on open bills', function () {
    Livewire::withQueryParams(['hide' => 'due_date,total,paid'])
        ->test('pages::reports.open-bills', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="sort-due_date"')
        ->assertDontSeeHtml('data-test="sort-total"')
        ->assertDontSeeHtml('data-test="sort-paid"')
        ->assertSeeHtml('data-test="sort-balance"');
});
