<?php

use App\Enums\CompanyRole;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    app()->forgetInstance('current_company');

    // The importer is only reachable by a member of the company (route middleware
    // in production; an explicit mount() check for the embedded/wizard path). Every
    // test acts as an owner of the company it drives.
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->ownedCompany = function (): Company {
        $company = Company::factory()->create();
        $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
        app()->instance('current_company', $company);

        return $company;
    };
});

it('renders the opening-balance wizard by default', function () {
    $company = ($this->ownedCompany)();

    Livewire::test('pages::migration.import', ['company' => $company])
        ->assertOk()
        ->assertSee('Conversion date')
        ->assertSet('run.mode', DataMigrationMode::OpeningBalance);
});

it('forbids a user who is not a member of the target company', function () {
    // Regression for the setup-wizard cross-tenant IDOR: the importer must refuse
    // to mount against a company the authenticated user does not belong to, even
    // when the Company model is handed in directly (the embedded wizard path).
    $victim = Company::factory()->create();

    Livewire::test('pages::migration.import', ['company' => $victim])
        ->assertForbidden();
});

it('switches to full-history mode and exposes the general ledger step', function () {
    $company = ($this->ownedCompany)();

    Livewire::test('pages::migration.import', ['company' => $company])
        ->call('switchMode', 'full_history')
        ->assertSet('run.mode', DataMigrationMode::FullHistory)
        ->call('jumpTo', 6)
        ->assertSet('stepKey', 'general_ledger')
        ->assertSee('Source format')
        ->assertSee('Import history');
});

it('shows the GL bulk upload when an action advances onto the step in the same request', function () {
    // Regression: advancing from another step onto general_ledger (here by skipping
    // vendors) reads the stale stepKey memo unless it is busted, which made the GL
    // bulk drag-and-drop fall back to the generic single-file "Upload CSV" branch
    // until a full page reload.
    $company = ($this->ownedCompany)();

    Livewire::test('pages::migration.import', ['company' => $company])
        ->call('switchMode', 'full_history')
        ->call('jumpTo', 5) // vendors
        ->assertSet('stepKey', 'vendors')
        ->call('skipStep') // advances onto general_ledger within this request
        ->assertSet('stepKey', 'general_ledger')
        ->assertSee('Source format')
        ->assertSee('Import history')
        ->assertDontSee('Upload CSV');
});

it('warns on the GL step when reconstruction is on but the chart was skipped', function () {
    $company = ($this->ownedCompany)();

    DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'status' => DataMigrationStatus::InProgress,
        'mode' => DataMigrationMode::FullHistory,
        'conversion_date' => CarbonImmutable::now(),
        'current_step' => 6, // general_ledger
        'reconstruct_documents' => true,
        'step_results' => ['chart_of_accounts' => ['skipped' => true, 'committed_at' => now()->toIso8601String()]],
        'started_at' => now(),
    ]);

    Livewire::test('pages::migration.import', ['company' => $company])
        ->assertSet('stepKey', 'general_ledger')
        ->assertSee('Import your chart of accounts first');

    app()->forgetInstance('current_company');
});
