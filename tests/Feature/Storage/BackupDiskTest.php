<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Backups on object storage. The ZIP is still assembled locally (ZipArchive
 * needs a real path); only the finished artifact is uploaded, and the disk it
 * landed on is recorded so download/prune/purge can find it later.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake('s3');

    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads the finished archive to the configured backup disk', function () {
    config()->set('filesystems.roles.backups', 's3');

    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Pending,
    ]);

    app(CompanyExporter::class)->export($backup);

    $backup->refresh();

    expect($backup->status)->toBe(CompanyBackupStatus::Ready)
        ->and($backup->disk)->toBe('s3')
        ->and($backup->file_path)->not->toBeNull()
        ->and($backup->sha256)->not->toBeNull();

    Storage::disk('s3')->assertExists($backup->file_path);

    // The local staging copy must not be left behind — backups are large.
    Storage::disk('local')->assertMissing($backup->file_path);
});

it('still writes locally when no backup disk is configured', function () {
    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Pending,
    ]);

    app(CompanyExporter::class)->export($backup);

    $backup->refresh();

    expect($backup->disk)->toBe('local');
    Storage::disk('local')->assertExists($backup->file_path);
});

it('redirects to a presigned URL for a backup on object storage', function () {
    $path = "backups/{$this->company->id}/backup.zip";
    Storage::disk('s3')->put($path, 'PK'.str_repeat("\0", 32));

    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Ready,
        'disk' => 's3',
        'file_path' => $path,
        'file_size_bytes' => 34,
        'sha256' => str_repeat('a', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->owner);

    $this->get(URL::temporarySignedRoute(
        'settings.backup.download',
        now()->addHour(),
        ['company' => $this->company, 'backup' => $backup->id],
    ))->assertRedirect();
});

it('does not mint a presigned URL for a request that fails authorization', function () {
    $path = "backups/{$this->company->id}/backup.zip";
    Storage::disk('s3')->put($path, 'PK'.str_repeat("\0", 32));

    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Ready,
        'disk' => 's3',
        'file_path' => $path,
        'expires_at' => now()->addDays(7),
    ]);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);
    $this->actingAs($admin);

    // Backups carry API keys, member emails and the full GL — Owner only.
    $this->get(URL::temporarySignedRoute(
        'settings.backup.download',
        now()->addHour(),
        ['company' => $this->company, 'backup' => $backup->id],
    ))->assertForbidden();
});

it('prunes an expired backup from the disk it was written to', function () {
    $path = "backups/{$this->company->id}/old.zip";
    Storage::disk('s3')->put($path, 'PK');

    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Ready,
        'disk' => 's3',
        'file_path' => $path,
        'file_size_bytes' => 2,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('backups:prune-expired')->assertExitCode(0);

    Storage::disk('s3')->assertMissing($path);
    expect($backup->fresh()->status)->toBe(CompanyBackupStatus::Expired);
});

it('treats a backup with no recorded disk as local', function () {
    $path = "backups/{$this->company->id}/legacy.zip";
    Storage::disk('local')->put($path, 'PK');

    $backup = CompanyBackup::factory()->for($this->company)->create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyBackupStatus::Ready,
        'disk' => '',
        'file_path' => $path,
        'file_size_bytes' => 2,
        'expires_at' => now()->subDay(),
    ]);

    expect($backup->storageDisk())->toBe('local');

    $this->artisan('backups:prune-expired')->assertExitCode(0);

    Storage::disk('local')->assertMissing($path);
});
