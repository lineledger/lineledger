<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the backup & export page for owners', function () {
    $this->actingAs($this->owner);

    $this->get(route('settings.backup-and-export', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Backup & Export');
});

it('forbids non-owner roles from viewing the page', function () {
    $admin = User::factory()->create();
    $this->company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);

    $this->actingAs($admin);

    $this->get(route('settings.backup-and-export', ['company' => $this->company->slug]))
        ->assertForbidden();
});

it('creates a pending backup row and dispatches the export job when an owner clicks create', function () {
    Bus::fake([ExportCompanyDataJob::class]);

    $this->actingAs($this->owner);

    Livewire::test('pages::settings.backup-and-export', ['company' => $this->company])
        ->call('createBackup')
        ->assertHasNoErrors();

    $backup = CompanyBackup::query()->where('company_id', $this->company->id)->first();

    expect($backup)->not->toBeNull()
        ->and($backup->status)->toBe(CompanyBackupStatus::Pending)
        ->and($backup->requested_by_user_id)->toBe($this->owner->id)
        ->and($backup->app_version)->toBe(config('version.app'))
        ->and((int) $backup->schema_version)->toBe((int) config('version.schema'));

    Bus::assertDispatched(
        ExportCompanyDataJob::class,
        fn (ExportCompanyDataJob $job): bool => $job->backup->is($backup),
    );
});

it('forbids non-owners from creating a backup via the action', function () {
    $admin = User::factory()->create();
    $this->company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);

    $this->actingAs($admin);

    $this->get(route('settings.backup-and-export', ['company' => $this->company->slug]))
        ->assertForbidden();

    expect(CompanyBackup::query()->where('company_id', $this->company->id)->count())->toBe(0);
});
