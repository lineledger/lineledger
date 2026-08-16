<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
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
    $this->liability = Account::query()->where('type', AccountType::Liability->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function liabilityEntry(string $date, int $cents, Account $liability): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => test()->bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $liability->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('nests a section under its subtype with a subtotal, leaving the type total unchanged', function () {
    $subtypeKey = $this->liability->subtype->value;
    $section = ReportSection::create(['statement' => 'balance_sheet', 'group_key' => $subtypeKey, 'name' => 'Taxes', 'sort_order' => 1]);
    $this->liability->update(['report_section_id' => $section->id]);
    liabilityEntry('2026-03-01', 10000, $this->liability);

    $report = Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-24')
        ->instance()
        ->report();

    $group = $report['liabilities'][$subtypeKey];
    $block = collect($group['blocks'])->firstWhere('type', 'section');

    expect($group['label'])->toBe($this->liability->subtype->label())
        ->and($block)->not->toBeNull()
        ->and($block['name'])->toBe('Taxes')
        ->and($block['subtotal'])->toBe(10000)
        ->and($report['total_liabilities'])->toBe(10000);
});

it('renders the subtype section header and subtotal', function () {
    $subtypeKey = $this->liability->subtype->value;
    $section = ReportSection::create(['statement' => 'balance_sheet', 'group_key' => $subtypeKey, 'name' => 'Taxes', 'sort_order' => 1]);
    $this->liability->update(['report_section_id' => $section->id]);
    liabilityEntry('2026-03-01', 10000, $this->liability);

    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-24')
        ->assertOk()
        ->assertSee('Taxes')
        ->assertSeeHtml('data-test="bs-section-subtotal-'.$section->id.'"');
});

it('still balances and exports with a section present', function () {
    $subtypeKey = $this->liability->subtype->value;
    $section = ReportSection::create(['statement' => 'balance_sheet', 'group_key' => $subtypeKey, 'name' => 'Taxes', 'sort_order' => 1]);
    $this->liability->update(['report_section_id' => $section->id]);
    liabilityEntry('2026-03-01', 10000, $this->liability);

    $component = Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOf', '2026-05-24');

    $report = $component->instance()->report();
    expect($report['total_assets'])->toBe($report['total_le']);

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Taxes')
        ->and($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});
