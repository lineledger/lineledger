<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockMovement;
use App\Support\Reporting\SourceLinkResolver;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->resolver = app(SourceLinkResolver::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('resolves a source-document URL from a journal entry', function () {
    $entry = new JournalEntry(['source_type' => Invoice::class, 'source_id' => 123]);

    expect($this->resolver->urlFor($entry, $this->company))->toContain('/invoices/123');
});

it('falls back to the journal-entry view when there is no source document', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-1', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);

    expect($this->resolver->urlFor($entry->fresh(), $this->company))->toContain('/journal/');
});

it('returns null for an unmapped source type', function () {
    $entry = new JournalEntry(['source_type' => StockMovement::class, 'source_id' => 1]);

    expect($this->resolver->urlFor($entry, $this->company))->toBeNull();
});

it('lists posted journal lines for an account and links to the source', function () {
    $account = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry = JournalEntry::create([
        'entry_no' => 'JE-100',
        'entry_date' => CarbonImmutable::now()->toDateString(),
        'is_posted' => true,
        'source_type' => Invoice::class,
        'source_id' => 5,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit_cents' => 0,
        'credit_cents' => 10000,
        'entry_date' => CarbonImmutable::now()->toDateString(),
        'is_posted' => true,
    ]);

    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('accountId', $account->id)
        ->set('startDate', CarbonImmutable::now()->subMonth()->toDateString())
        ->set('endDate', CarbonImmutable::now()->addDay()->toDateString())
        ->assertSee('JE-100')
        ->assertSeeHtml('data-test="txn-source-link"');
});

it('offers CSV, Excel and PDF downloads on the transactions report', function () {
    Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->assertSeeHtml('wire:click="exportCsv"')
        ->assertSeeHtml('wire:click="exportXlsx"')
        ->assertSeeHtml('wire:click="exportPdf"');
});

it('exports transactions as a CSV of the filtered posted lines', function () {
    $account = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-CSV', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit_cents' => 0,
        'credit_cents' => 12345,
        'entry_date' => CarbonImmutable::now()->toDateString(),
        'is_posted' => true,
    ]);

    $response = Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('accountId', $account->id)
        ->set('startDate', CarbonImmutable::now()->subMonth()->toDateString())
        ->set('endDate', CarbonImmutable::now()->addDay()->toDateString())
        ->instance()
        ->exportCsv();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Date,"Entry #",Account,Name,Memo,Debit,Credit')
        ->and($csv)->toContain('JE-CSV')
        ->and($csv)->toContain('123.45');
});

it('exports transactions as XLSX and PDF without error', function () {
    $account = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-XL', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit_cents' => 0,
        'credit_cents' => 5000,
        'entry_date' => CarbonImmutable::now()->toDateString(),
        'is_posted' => true,
    ]);

    $component = Livewire::test('pages::reports.transactions', ['company' => $this->company])
        ->set('accountId', $account->id)
        ->set('startDate', CarbonImmutable::now()->subMonth()->toDateString())
        ->set('endDate', CarbonImmutable::now()->addDay()->toDateString());

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('renders account drill links on the balance sheet', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-BS', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $bank->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $ar->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);

    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', CarbonImmutable::now()->addDay()->toDateString())
        ->assertSeeHtml('data-test="drill-account"');
});

it('renders account drill links on the cash flow statement', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    // A non-cash balance account change shows as a cash-flow activity line.
    $entry = JournalEntry::create(['entry_no' => 'JE-CF', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $ar->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);

    Livewire::test('pages::reports.cash-flow', ['company' => $this->company])
        ->set('preset', 'this_fiscal_year')
        ->assertSeeHtml('data-test="drill-account"');
});

it('carries the active class filter into income-statement account drill links', function () {
    // Regression guard: the P&L drill-down dropped the active class/location filter,
    // so the drilled transactions did not reconcile to the class-filtered figure.
    $this->company->update(['features_classes' => true]);
    $class = Classification::create(['name' => 'West Division', 'is_active' => true]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $entry = JournalEntry::create(['entry_no' => 'JE-CLS', 'entry_date' => CarbonImmutable::now()->toDateString(), 'is_posted' => true]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $income->id,
        'debit_cents' => 0,
        'credit_cents' => 10000,
        'entry_date' => CarbonImmutable::now()->toDateString(),
        'is_posted' => true,
        'class_id' => $class->id,
    ]);

    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('classId', $class->id)
        ->set('preset', 'this_fiscal_year')
        ->assertSeeHtml('data-test="drill-account"')
        ->assertSeeHtml('class='.$class->id);
});
