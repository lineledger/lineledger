<?php

use App\Enums\CompanyRole;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeImportRun(Company $company, DataMigrationStatus $status): DataMigrationRun
{
    return DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'status' => $status,
        'mode' => DataMigrationMode::OpeningBalance,
        'conversion_date' => now(),
        'current_step' => 2,
        'started_at' => now(),
    ]);
}

it('shows the resume banner when an import is in progress', function () {
    makeImportRun($this->company, DataMigrationStatus::InProgress);

    Livewire::test('pages::dashboard.index', ['company' => $this->company])
        ->assertSee('Finish setting up your company');
});

it('does not show the banner when there is no in-progress import', function () {
    Livewire::test('pages::dashboard.index', ['company' => $this->company])
        ->assertDontSee('Finish setting up your company');
});

it('hides the banner once the import is completed', function () {
    makeImportRun($this->company, DataMigrationStatus::Completed);

    Livewire::test('pages::dashboard.index', ['company' => $this->company])
        ->assertDontSee('Finish setting up your company');
});

it('dismisses the banner for good and persists the choice', function () {
    makeImportRun($this->company, DataMigrationStatus::InProgress);

    Livewire::test('pages::dashboard.index', ['company' => $this->company])
        ->assertSee('Finish setting up your company')
        ->call('dismissSetupBanner')
        ->assertDontSee('Finish setting up your company');

    expect(data_get($this->company->fresh()->settings, 'setup.migration_banner_dismissed'))->toBeTrue();
});
