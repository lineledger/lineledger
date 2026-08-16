<?php

use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('memorizes the current report view with its settings', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'last_fiscal_year')
        ->set('reportTitle', 'My Saved P&L')
        ->set('memorizeName', 'Annual P&L')
        ->set('memorizeNewGroup', 'Year End')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $this->user->id)->first();

    expect($memorized)->not->toBeNull()
        ->and($memorized->report_key)->toBe('reports.income-statement')
        ->and($memorized->name)->toBe('Annual P&L')
        ->and($memorized->settings['preset'])->toBe('last_fiscal_year')
        ->and($memorized->settings['reportTitle'])->toBe('My Saved P&L')
        ->and($memorized->group->name)->toBe('Year End');
});

it('re-applies a memorized view on run', function () {
    $memorized = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.income-statement',
        'name' => 'Saved',
        'settings' => ['preset' => 'last_fiscal_year', 'startDate' => '2025-01-01', 'endDate' => '2025-12-31', 'reportTitle' => 'Restored'],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('preset', 'last_fiscal_year')
        ->assertSet('startDate', '2025-01-01')
        ->assertSet('reportTitle', 'Restored');
});

it('will not apply another user\'s memorized report', function () {
    $other = User::factory()->create();
    $memorized = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $other->id,
        'report_key' => 'reports.income-statement',
        'name' => 'Theirs',
        'settings' => ['reportTitle' => 'Should not load'],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('reportTitle', '');
});

it('will not apply a memorized report meant for a different report', function () {
    $memorized = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.balance-sheet',
        'name' => 'BS',
        'settings' => ['reportTitle' => 'Wrong report'],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('reportTitle', '');
});

it('lists and deletes memorized reports', function () {
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'name' => 'Year End']);
    $report = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'memorized_report_group_id' => $group->id,
        'report_key' => 'reports.income-statement',
        'name' => 'Annual P&L',
        'settings' => [],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->assertSee('Annual P&L')
        ->assertSee('Year End')
        ->call('delete', $report->id)
        ->assertDontSee('Annual P&L');

    $this->assertDatabaseMissing('memorized_reports', ['id' => $report->id]);
});

it('flags a memorized report whose key has left the catalog as unavailable', function () {
    // e.g. a feature was turned off or the report was removed — its key no longer resolves.
    $report = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.removed-report',
        'name' => 'Old Report',
        'settings' => [],
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->assertSee('Old Report')
        ->assertSeeHtml('data-test="memorized-unavailable"') // shows an "Unavailable" badge
        ->assertDontSeeHtml('data-test="memorized-run"')     // no Run button
        ->assertSeeHtml('data-test="memorized-delete"');     // Delete still offered

    expect($report->fresh())->not->toBeNull(); // not auto-deleted — the user decides
});
