<?php

use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('shows report notes on the income statement page', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->assertSeeHtml('data-test="report-notes"')
        ->set('reportNotes', 'Unaudited — management use only.')
        ->assertSee('Unaudited — management use only.');
});

it('shows report notes on the balance sheet page', function () {
    Livewire::test('pages::reports.balance-sheet', ['company' => $this->company])
        ->set('reportNotes', 'Prepared on a cash basis.')
        ->assertSee('Prepared on a cash basis.');
});

it('memorizes notes and restores them on run', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('reportNotes', 'See covenant schedule B.')
        ->set('memorizeName', 'Bank P&L')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $this->user->id)->first();

    expect($memorized->settings['reportNotes'])->toBe('See covenant schedule B.');

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('reportNotes', 'See covenant schedule B.');
});

it('truncates notes beyond 4000 characters', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('reportNotes', str_repeat('a', 4321))
        ->assertSet('reportNotes', str_repeat('a', 4000));
});

it('still downloads the PDF with notes set', function () {
    Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('reportNotes', "Line one.\nLine two.")
        ->call('exportPdf')
        ->assertFileDownloaded();
});
