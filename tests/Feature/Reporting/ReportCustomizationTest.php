<?php

use App\Models\Company;
use Livewire\Livewire;

beforeEach(function () {
    // Fiscal year starting in January; "today" is pinned by the harness to 2026.
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('shows a custom report title in the heading (range report)', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->assertSee('Income Statement')
        ->set('reportTitle', 'Operating P&L — Q1')
        ->assertSee('Operating P&L — Q1')
        ->assertDontSee('>Income Statement<'); // default heading replaced
});

it('shows a custom report title in the heading (as-of report)', function () {
    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->assertSee('Balance Sheet')
        ->set('reportTitle', 'Statement of Financial Position')
        ->assertSee('Statement of Financial Position');
});

it('resolves a date-range preset to fiscal-aware start and end (range report)', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'last_fiscal_year')
        ->assertSet('startDate', '2025-01-01')
        ->assertSet('endDate', '2025-12-31');
});

it('snaps the as-of date to the period end for a preset (as-of report)', function () {
    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('asOfPreset', 'last_fiscal_year')
        ->assertSet('asOf', '2025-12-31');
});

it('reverts the preset to custom when the user edits a date by hand', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'last_fiscal_year')
        ->set('startDate', '2025-03-01')
        ->assertSet('preset', 'custom');
});
