<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\MemorizedReport;
use App\Models\User;
use App\Support\Reporting\ReportNumberFormat;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Post an expense so the income statement's net income goes negative. */
function numberFormatExpenseEntry(string $date, int $cents): void
{
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

// ───────────────────────────── value object ─────────────────────────────

it('formats cents under each negative style', function () {
    $minus = new ReportNumberFormat('minus', 'cents');
    expect($minus->format(123456))->toBe('1,234.56')
        ->and($minus->format(-123456))->toBe('-1,234.56')
        ->and($minus->format(0))->toBe('0.00')
        ->and($minus->format(-1))->toBe('-0.01');

    $paren = new ReportNumberFormat('paren', 'cents');
    expect($paren->format(123456))->toBe('1,234.56')
        ->and($paren->format(-123456))->toBe('(1,234.56)')
        ->and($paren->format(0))->toBe('0.00')
        ->and($paren->format(-1))->toBe('(0.01)');

    // 'red' renders the minus form — the colour comes from cssClass().
    $red = new ReportNumberFormat('red', 'cents');
    expect($red->format(-123456))->toBe('-1,234.56')
        ->and($red->cssClass(-123456))->toBe('text-red-600')
        ->and($red->cssClass(123456))->toBe('')
        ->and($red->cssClass(0))->toBe('')
        ->and($red->pdfClass(-1))->toBe('neg')
        ->and($minus->cssClass(-123456))->toBe('')
        ->and($paren->cssClass(-123456))->toBe('');
});

it('rounds whole-dollar and thousands units for display', function () {
    $whole = new ReportNumberFormat('minus', 'whole');
    expect($whole->format(123456))->toBe('1,235')
        ->and($whole->format(-123456))->toBe('-1,235')
        ->and($whole->format(0))->toBe('0')
        ->and($whole->format(-1))->toBe('0'); // -1¢ rounds to zero dollars — no stray sign

    expect((new ReportNumberFormat('paren', 'whole'))->format(-123456))->toBe('(1,235)');

    $thousands = new ReportNumberFormat('minus', 'thousands');
    expect($thousands->format(123456789))->toBe('1,235')
        ->and($thousands->format(-123456789))->toBe('-1,235')
        ->and($thousands->format(-1))->toBe('0')
        ->and($thousands->unitsSuffix())->toBe(' · $ in thousands');

    expect($whole->unitsSuffix())->toBeNull()
        ->and((new ReportNumberFormat)->unitsSuffix())->toBeNull();
});

it('falls back to defaults for invalid props', function () {
    $fallback = ReportNumberFormat::fromProps('shout', 'bushels');
    expect($fallback->negativeStyle)->toBe('minus')
        ->and($fallback->units)->toBe('cents');

    $kept = ReportNumberFormat::fromProps('paren', 'thousands');
    expect($kept->negativeStyle)->toBe('paren')
        ->and($kept->units)->toBe('thousands');
});

it('maps each negative style to an Excel money format, keeping full precision', function () {
    // Spreadsheets ignore the units preference — values stay 2-decimal so they sum.
    expect((new ReportNumberFormat('minus', 'thousands'))->xlsxMoneyFormat())->toBe('#,##0.00;-#,##0.00')
        ->and((new ReportNumberFormat('paren', 'whole'))->xlsxMoneyFormat())->toBe('#,##0.00;(#,##0.00)')
        ->and((new ReportNumberFormat('red', 'cents'))->xlsxMoneyFormat())->toBe('#,##0.00;[Red]-#,##0.00');
});

// ──────────────────────────── report pages ──────────────────────────────

it('renders negatives in parentheses when ?neg=paren', function () {
    numberFormatExpenseEntry('2026-02-10', 123456);

    Livewire::withQueryParams(['neg' => 'paren'])
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSet('negativeStyle', 'paren')
        // Cell-targeted: the control-bar option labels also contain "(1,234.56)".
        ->assertSeeHtml('(1,234.56)</td>'); // net income is -1,234.56
});

it('drops decimals when ?units=whole and notes thousands in the subtitle', function () {
    numberFormatExpenseEntry('2026-02-10', 123456);

    Livewire::withQueryParams(['units' => 'whole'])
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        // Cell-targeted: the control-bar option labels also contain "1,234.56".
        ->assertSeeHtml('1,235</td>')
        ->assertDontSeeHtml('1,234.56</td>')
        ->set('numberUnits', 'thousands')
        ->assertSee('$ in thousands');
});

it('falls back to the minus style for an unknown ?neg value', function () {
    numberFormatExpenseEntry('2026-02-10', 123456);

    Livewire::withQueryParams(['neg' => 'sparkles'])
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        // Cell-targeted: the control-bar option labels also contain "(1,234.56)".
        ->assertSeeHtml('-1,234.56</td>')
        ->assertDontSeeHtml('(1,234.56)</td>');
});

it('paints negative amounts red only under the red style', function () {
    numberFormatExpenseEntry('2026-02-10', 123456);

    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('negativeStyle', 'red')
        ->assertSeeHtml('text-red-600')
        ->set('negativeStyle', 'minus')
        ->assertDontSeeHtml('text-red-600');
});

it('memorizes and restores the number format', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('negativeStyle', 'paren')
        ->set('numberUnits', 'thousands')
        ->set('memorizeName', 'Paren P&L')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $user->id)->first();

    expect($memorized->settings['negativeStyle'])->toBe('paren')
        ->and($memorized->settings['numberUnits'])->toBe('thousands');

    Livewire::actingAs($user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('negativeStyle', 'paren')
        ->assertSet('numberUnits', 'thousands');
});

it('still downloads XLSX and PDF exports with a paren negative style', function () {
    numberFormatExpenseEntry('2026-02-10', 123456);

    $component = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('negativeStyle', 'paren')
        ->set('numberUnits', 'thousands');

    $component->call('exportXlsx')->assertFileDownloaded();
    $component->call('exportPdf')->assertFileDownloaded();
});
