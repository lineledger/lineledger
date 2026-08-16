<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\ReportSection;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    // A true expense-subtype account: the first expense by code is COGS, which
    // lands in the 'cogs' bucket rather than 'expense'.
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function expenseEntry(string $date, int $cents, Account $expense): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => test()->bank->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('nests an assigned account under its section with a subtotal, leaving the bucket total unchanged', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);
    expenseEntry('2026-03-01', 10000, $this->expense);

    $report = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->instance()
        ->report();

    $block = collect($report['expense'])->firstWhere('type', 'section');

    expect($block)->not->toBeNull()
        ->and($block['name'])->toBe('Operating')
        ->and($block['id'])->toBe($section->id)
        ->and($block['subtotal'])->toBe(10000)
        ->and($report['total_expense'])->toBe(10000);
});

it('renders the section header and subtotal in the report', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);
    expenseEntry('2026-03-01', 10000, $this->expense);

    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertOk()
        ->assertSee('Operating')
        ->assertSeeHtml('data-test="is-section-subtotal-'.$section->id.'"');
});

it('hides a section whose accounts all net to zero', function () {
    // Section exists and an account is assigned, but it has no activity in the period.
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Empty', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);

    $report = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->instance()
        ->report();

    expect(collect($report['expense'])->firstWhere('type', 'section'))->toBeNull();
});

it('includes the section name and subtotal in the CSV export', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);
    expenseEntry('2026-03-01', 10000, $this->expense);

    $response = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->instance()
        ->exportCsv();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Operating')
        ->and($csv)->toContain('Total Operating');
});

it('exports XLSX and PDF without error when sections exist', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);
    expenseEntry('2026-03-01', 10000, $this->expense);

    // Also book income so the statement has all three buckets.
    $income = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => '2026-03-01', 'is_posted' => true]);
    $income->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 25000, 'credit_cents' => 0, 'line_order' => 0]);
    $income->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 25000, 'line_order' => 1]);

    $component = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31');

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});
