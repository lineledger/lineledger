<?php

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use App\Support\Contacts\ContactLinkResolver;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Transactions report — Name column drill-through
|--------------------------------------------------------------------------
| Each Name cell links to the same report filtered to that contact over the
| current range, except on rows whose contact is already the active filter.
| Links built by ContactLinkResolver carry an explicit all-time range because
| the report otherwise defaults to this fiscal year to date.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * One posted journal line carrying the given contact, dated inside 2026.
 */
function nameDrillLine(Contact $contact, Account $account, string $memo): JournalLine
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-ND-'.$contact->id,
        'entry_date' => '2026-05-01',
        'memo' => $memo,
        'is_posted' => true,
    ]);

    return JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'contact_id' => $contact->id,
        'debit_cents' => 5000,
        'credit_cents' => 0,
        'entry_date' => '2026-05-01',
        'is_posted' => true,
        'memo' => $memo,
    ]);
}

it('renders each name as a drill link to that contact over the current range', function () {
    $alpha = Contact::factory()->otherName()->create(['display_name' => 'Alpha Payee']);
    nameDrillLine($alpha, $this->expenseAccount, 'Alpha memo');

    $expected = route('reports.transactions', [
        'company' => $this->company->slug,
        'contact' => $alpha->id,
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]);

    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSeeHtml('data-test="drill-contact"')
        ->assertSee($expected)
        ->assertSee('Alpha Payee');
});

it('lists only that contact\'s lines once drilled', function () {
    $alpha = Contact::factory()->otherName()->create(['display_name' => 'Alpha Payee']);
    $beta = Contact::factory()->vendor()->create(['display_name' => 'Beta Vendor']);
    nameDrillLine($alpha, $this->expenseAccount, 'Alpha memo');
    nameDrillLine($beta, $this->expenseAccount, 'Beta memo');

    Livewire::withQueryParams(['contact' => $alpha->id, 'start' => '2026-01-01', 'end' => '2026-12-31'])
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->assertSet('contactId', $alpha->id)
        ->assertSee('Alpha memo')
        ->assertDontSee('Beta memo')
        ->assertDontSee('Beta Vendor');
});

it('drops the anchor on rows whose contact is the active filter', function () {
    $alpha = Contact::factory()->otherName()->create(['display_name' => 'Alpha Payee']);
    nameDrillLine($alpha, $this->expenseAccount, 'Alpha memo');

    Livewire::withQueryParams(['contact' => $alpha->id, 'start' => '2026-01-01', 'end' => '2026-12-31'])
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->assertSeeHtml('data-test="txn-row"')
        ->assertSee('Alpha Payee')
        ->assertDontSeeHtml('data-test="drill-contact"');
});

it('keeps the drill anchor on rows for other contacts while one is filtered', function () {
    $alpha = Contact::factory()->otherName()->create(['display_name' => 'Alpha Payee']);
    $beta = Contact::factory()->vendor()->create(['display_name' => 'Beta Vendor']);
    nameDrillLine($alpha, $this->expenseAccount, 'Alpha memo');
    nameDrillLine($beta, $this->expenseAccount, 'Beta memo');

    // Unfiltered: both rows link. Filtered to Alpha: nothing to drill to.
    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSee(route('reports.transactions', ['company' => $this->company->slug, 'contact' => $alpha->id, 'start' => '2026-01-01', 'end' => '2026-12-31']))
        ->assertSee(route('reports.transactions', ['company' => $this->company->slug, 'contact' => $beta->id, 'start' => '2026-01-01', 'end' => '2026-12-31']))
        ->set('contactId', $alpha->id)
        ->assertDontSeeHtml('data-test="drill-contact"');
});

it('shows a cheque dated last fiscal year through the resolver\'s all-time URL', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $other = Contact::factory()->otherName()->create(['display_name' => 'Raffle winner']);
    $lastYear = $this->company->currentDateTime()->subYear()->toDateString();

    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-OLD-1',
        'cheque_date' => $lastYear,
        'payee_contact_id' => $other->id,
        'payee_name' => null,
        'lines' => [[
            'account_id' => $this->expenseAccount->id,
            'description' => 'Raffle prize',
            'amount_cents' => 25000,
        ]],
    ]);
    app(ChequePoster::class)->post($cheque);

    $contactIds = JournalLine::query()
        ->where('journal_entry_id', $cheque->fresh()->journal_entry_id)
        ->pluck('contact_id')
        ->unique()
        ->values()
        ->all();

    expect($contactIds)->toBe([$other->id]);

    // A bare ?contact= link falls back to this fiscal year to date and hides it…
    $this->get(route('reports.transactions', ['company' => $this->company->slug, 'contact' => $other->id]))
        ->assertOk()
        ->assertDontSee('data-test="txn-row"', false)
        ->assertSee('No transactions match these filters.');

    // …which is why every drill link carries the explicit all-time range.
    $this->get(app(ContactLinkResolver::class)->transactionsUrl($other, $this->company))
        ->assertOk()
        ->assertSee('data-test="txn-row"', false)
        ->assertSee('CHQ-OLD-1')
        ->assertDontSee('No transactions match these filters.');
});

it('links the group header to that name when the report is grouped by name', function () {
    $alpha = Contact::factory()->otherName()->create(['display_name' => 'Alpha Payee']);
    nameDrillLine($alpha, $this->expenseAccount, 'Alpha memo');

    $expected = route('reports.transactions', [
        'company' => $this->company->slug,
        'contact' => $alpha->id,
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]);

    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('groupBy', 'contact')
        ->assertSeeHtml('data-test="drill-contact-group"')
        ->assertSee($expected);

    // Already filtered to that name: the header is plain text.
    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('groupBy', 'contact')
        ->set('contactId', $alpha->id)
        ->assertDontSeeHtml('data-test="drill-contact-group"')
        ->assertSee('Alpha Payee');
});
