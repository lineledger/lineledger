<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollCheque;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Payroll detail on general-ledger surfaces
|--------------------------------------------------------------------------
| A member who can't open pay runs still reads the ledger, but a payroll
| line shows "Payroll" for its memo and name, with no link through to the
| entry, and amounts unchanged (PayrollDetail). The Owner sees everything.
*/

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    // Ledger, reports and banking, but not payroll.
    $this->member = customSectionsMember($this->company, [Section::Accounting, Section::Reports, Section::Banking]);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->employee = Contact::factory()->create(['display_name' => 'Jane Employee', 'is_employee' => true]);

    // Today, so it falls inside every page's default (fiscal year to date) range.
    $date = $this->company->currentDateTime()->toDateString();

    $this->entry = JournalEntry::create([
        'entry_no' => 'PAY-0007',
        'entry_date' => $date,
        'memo' => 'Payroll cheque 7 — Jane Employee',
        'source_type' => PayrollCheque::class,
        'is_posted' => true,
    ]);

    JournalLine::create([
        'journal_entry_id' => $this->entry->id,
        'account_id' => $this->expense->id,
        'contact_id' => $this->employee->id,
        'debit_cents' => 123_456,
        'credit_cents' => 0,
        'memo' => 'Net pay',
        'line_order' => 0,
    ]);

    JournalLine::create([
        'journal_entry_id' => $this->entry->id,
        'account_id' => $this->bank->id,
        'contact_id' => $this->employee->id,
        'debit_cents' => 0,
        'credit_cents' => 123_456,
        'memo' => 'Payroll cheque 7 — Jane Employee',
        'line_order' => 1,
    ]);

    $this->showUrl = route('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->entry_no]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function payrollRedactionCsv(Testable $page): string
{
    return (string) base64_decode((string) data_get($page->call('exportCsv')->effects, 'download.content'));
}

it('withholds payroll detail from the general ledger, on screen and in its exports', function () {
    $member = $this->actingAs($this->member)->get(route('reports.general-ledger', ['company' => $this->company->slug]))->assertOk();
    $member->assertDontSee('Jane Employee')->assertSee('Payroll')->assertSee('1,234.56')
        ->assertSee('PAY-0007')->assertDontSee($this->showUrl);

    $single = Livewire::actingAs($this->member)
        ->test('pages::reports.general-ledger', ['company' => $this->company])
        ->set('accountId', (string) $this->bank->id);
    $single->assertDontSee('Jane Employee')->assertSee('Payroll')->assertDontSee($this->showUrl);

    expect(payrollRedactionCsv($single))->not->toContain('Jane Employee')->toContain('Payroll')->toContain('1234.56')
        ->and(payrollRedactionCsv($single->set('accountId', 'all')))->not->toContain('Jane Employee')->toContain('Payroll');

    $this->actingAs($this->owner)->get(route('reports.general-ledger', ['company' => $this->company->slug]))
        ->assertSee('Jane Employee')->assertSee($this->showUrl);
    expect(payrollRedactionCsv(Livewire::actingAs($this->owner)->test('pages::reports.general-ledger', ['company' => $this->company])))
        ->toContain('Jane Employee');
});

it('withholds payroll memos and names from the transactions report, its filters and its exports', function () {
    $url = route('reports.transactions', ['company' => $this->company->slug]);

    $this->actingAs($this->member)->get($url)->assertOk()
        ->assertDontSee('Jane Employee')->assertSee('Payroll')->assertSee('1,234.56');

    $page = Livewire::actingAs($this->member)->test('pages::reports.transactions', ['company' => $this->company]);
    $page->set('groupBy', 'contact')->assertDontSee('Jane Employee')->assertSee('Payroll');
    expect(payrollRedactionCsv($page))->not->toContain('Jane Employee')->toContain('Payroll');

    // Filtering to the employee finds none of their pay.
    $page->set('groupBy', 'none')->set('contactId', $this->employee->id)->assertDontSee('1,234.56');

    $this->actingAs($this->owner)->get($url)->assertSee('Jane Employee');
});

it('withholds payroll memos and the entry link from the bank register', function () {
    $url = route('banking.register', ['company' => $this->company->slug, 'account' => $this->bank->id]);
    $showById = route('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id]);

    $this->actingAs($this->member)->get($url)->assertOk()
        ->assertDontSee('Jane Employee')->assertSee('Payroll')->assertSee('PAY-0007')->assertDontSee($showById);

    $this->actingAs($this->owner)->get($url)->assertSee('Jane Employee')->assertSee($showById);
});

it('withholds payroll memos and the entry link from the journal list, and its memo search', function () {
    $url = route('journal.index', ['company' => $this->company->slug]);
    $showById = route('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id]);

    $this->actingAs($this->member)->get($url)->assertOk()
        ->assertDontSee('Jane Employee')->assertSee('Payroll')->assertSee('PAY-0007')->assertDontSee($showById);

    Livewire::actingAs($this->member)->test('pages::journal.index', ['company' => $this->company])
        ->set('search', 'Jane')->assertDontSee('PAY-0007');

    $this->actingAs($this->owner)->get($url)->assertSee('Jane Employee')->assertSee($showById);
});

it('refuses a payroll entry to a member who cannot open pay runs, on load and on every update', function () {
    $this->actingAs($this->member)->get($this->showUrl)->assertForbidden();
    $this->actingAs($this->owner)->get($this->showUrl)->assertOk()->assertSee('Jane Employee');

    // A member who could open it, then loses payroll mid-session.
    $payroll = customSectionsMember($this->company, [Section::Accounting, Section::Payroll]);
    $page = Livewire::actingAs($payroll)->test('pages::journal.show', ['company' => $this->company, 'entry' => $this->entry])
        ->assertSee('Jane Employee');

    $this->company->memberships()->where('user_id', $payroll->id)->firstOrFail()
        ->update(['sections' => [Section::Accounting->value]]);

    $page->call('$refresh')->assertForbidden();
});

it('will not copy a payroll entry into a new or memorized journal entry for a member who cannot open pay runs', function () {
    foreach (['journal.create', 'recurring-journal.create'] as $route) {
        $url = route($route, ['company' => $this->company->slug, 'from' => $this->entry->id]);

        $this->actingAs($this->member)->get($url)->assertOk()->assertDontSee('Jane Employee')->assertDontSee('1234.56');
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Jane Employee')->assertSee('1234.56');
    }
});

it('withholds the void of a payroll entry, which copies its memos and employee but has no source of its own', function () {
    $reversal = app(JournalPoster::class)->void($this->entry, null, 'Void of payroll cheque 7 — Jane Employee');
    $reversalUrl = route('journal.show', ['company' => $this->company->slug, 'entry' => $reversal->id]);

    expect($reversal->source_type)->toBeNull();

    $this->actingAs($this->member)->get(route('journal.index', ['company' => $this->company->slug]))->assertOk()
        ->assertDontSee('Jane Employee')->assertSee($reversal->entry_no)->assertDontSee($reversalUrl);
    $this->actingAs($this->member)->get(route('reports.transactions', ['company' => $this->company->slug]))->assertOk()
        ->assertDontSee('Jane Employee');
    $this->actingAs($this->member)->get(route('reports.general-ledger', ['company' => $this->company->slug]))->assertOk()
        ->assertDontSee('Jane Employee');
    $this->actingAs($this->member)->get($reversalUrl)->assertForbidden();

    // The SQL filters skip it too: memo search, name filter and name grouping.
    Livewire::actingAs($this->member)->test('pages::journal.index', ['company' => $this->company])
        ->set('search', 'Jane')->assertDontSee($reversal->entry_no);
    Livewire::actingAs($this->member)->test('pages::reports.transactions', ['company' => $this->company])
        ->set('groupBy', 'contact')->assertDontSee('Jane Employee')
        ->set('groupBy', 'none')->set('contactId', $this->employee->id)->assertDontSee('1,234.56');

    $this->actingAs($this->owner)->get($reversalUrl)->assertOk()->assertSee('Jane Employee');
});
