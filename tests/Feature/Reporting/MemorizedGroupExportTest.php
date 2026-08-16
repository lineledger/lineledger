<?php

use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\User;
use App\Services\Reporting\Render\ReportBundleBuilder;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function exportGroupReport(Company $company, User $user, MemorizedReportGroup $group, string $key, string $name, array $settings = []): MemorizedReport
{
    return MemorizedReport::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'memorized_report_group_id' => $group->id,
        'report_key' => $key,
        'name' => $name,
        'settings' => $settings,
    ]);
}

it('downloads a memorized group as a zip of per-report PDFs', function () {
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'name' => 'Year End']);
    exportGroupReport($this->company, $this->user, $group, 'reports.income-statement', 'Q1 P&L', [
        'preset' => 'custom', 'startDate' => '2026-01-01', 'endDate' => '2026-03-31',
    ]);
    exportGroupReport($this->company, $this->user, $group, 'reports.balance-sheet', 'Q1 BS', [
        'asOfPreset' => 'custom', 'asOf' => '2026-03-31',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->assertSeeHtml('data-test="export-group"')
        ->call('exportGroup', $group->id)
        ->assertHasNoErrors();

    expect(data_get($component->effects, 'download.name'))->toEndWith('.zip');
});

it('bundles exactly the renderable subset, skipping unknown keys and deduping filenames', function () {
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'name' => 'Year End']);
    $settings = ['preset' => 'custom', 'startDate' => '2026-01-01', 'endDate' => '2026-03-31'];

    $reports = collect([
        exportGroupReport($this->company, $this->user, $group, 'reports.income-statement', 'Q1 P&L', $settings),
        // Same report + same dates → same artifact filename; must be deduped in the zip.
        exportGroupReport($this->company, $this->user, $group, 'reports.income-statement', 'Q1 P&L Copy', $settings),
        exportGroupReport($this->company, $this->user, $group, 'reports.balance-sheet', 'Q1 BS', [
            'asOfPreset' => 'custom', 'asOf' => '2026-03-31',
        ]),
        // Key absent from both the catalog and the renderable registry → skipped.
        exportGroupReport($this->company, $this->user, $group, 'reports.nonexistent', 'Gone', []),
    ]);

    $availableKeys = ['reports.income-statement', 'reports.balance-sheet', 'reports.nonexistent'];

    $artifact = app(ReportBundleBuilder::class)->bundle($this->company, $reports, $availableKeys, $group->name);

    expect(substr($artifact->bytes, 0, 4))->toBe("PK\x03\x04")
        ->and($artifact->mime)->toBe('application/zip')
        ->and($artifact->filename)->toBe('year-end-reports-'.$this->company->currentDateTime()->format('Y-m-d').'.zip');

    $tmp = tempnam(sys_get_temp_dir(), 'bundle-test-');
    file_put_contents($tmp, $artifact->bytes);

    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();
    unlink($tmp);

    sort($names);

    expect($names)->toBe([
        'balance-sheet-2026-03-31.pdf',
        'income-statement-2026-01-01-2026-03-31.pdf',
        'q1-pl-copy-income-statement-2026-01-01-2026-03-31.pdf',
    ]);
});

it('throws when no report in the group is renderable', function () {
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'name' => 'Stale']);
    $reports = collect([
        exportGroupReport($this->company, $this->user, $group, 'reports.nonexistent', 'Gone', []),
    ]);

    expect(fn () => app(ReportBundleBuilder::class)->bundle($this->company, $reports, ['reports.nonexistent'], $group->name))
        ->toThrow(RuntimeException::class);
});

it('surfaces a toast instead of crashing when the whole group is unrenderable', function () {
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'name' => 'Stale']);
    exportGroupReport($this->company, $this->user, $group, 'reports.nonexistent', 'Gone', []);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        // No renderable report → no Download group button offered.
        ->assertDontSeeHtml('data-test="export-group"')
        ->call('exportGroup', $group->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect(data_get($component->effects, 'download'))->toBeNull();
});

it('will not export another user\'s group', function () {
    $other = User::factory()->create();
    $group = MemorizedReportGroup::create(['company_id' => $this->company->id, 'user_id' => $other->id, 'name' => 'Theirs']);
    exportGroupReport($this->company, $other, $group, 'reports.income-statement', 'Their P&L', [
        'preset' => 'custom', 'startDate' => '2026-01-01', 'endDate' => '2026-03-31',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->call('exportGroup', $group->id)
        ->assertHasNoErrors();

    expect(data_get($component->effects, 'download'))->toBeNull();
});
