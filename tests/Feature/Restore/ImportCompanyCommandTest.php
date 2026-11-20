<?php

use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Jobs\RestoreCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\BackupRoundTrip;

it('stages the bundle on the backups disk before queueing the restore', function () {
    // The queue worker may run on another machine, so the bundle has to be
    // somewhere both can reach — the backups disk, not this box's storage/.
    Storage::fake('local');
    Storage::fake('s3');
    config()->set('filesystems.roles.backups', 's3');
    Queue::fake();

    $user = User::factory()->create();
    $file = tempnam(sys_get_temp_dir(), 'bundle-');
    file_put_contents($file, 'PK-bundle-bytes');

    $this->artisan('backup:import', ['file' => $file, '--user' => $user->id])->assertExitCode(0);

    $restore = CompanyRestore::query()->sole();

    expect($restore->disk)->toBe('s3')
        ->and($restore->file_size_bytes)->toBe(strlen('PK-bundle-bytes'))
        ->and($restore->sha256)->toBe(hash('sha256', 'PK-bundle-bytes'));

    Storage::disk('s3')->assertExists($restore->file_path);
    Queue::assertPushed(RestoreCompanyDataJob::class);

    @unlink($file);
});

describe('restoring from the command line', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');

        $this->owner = User::factory()->create(['email' => 'Owner@Example.test']);
        $this->company = Company::factory()->create(['name' => 'Command Line Ltd']);
        $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
        $this->zip = BackupRoundTrip::bundle($this->company);
    });

    it('restores into a new organization owned by the user named by email, as backup:restore', function () {
        $this->artisan('backup:restore', ['file' => $this->zip, '--owner' => 'owner@example.test', '--sync' => true])
            ->expectsOutputToContain('complete. New Company')
            ->assertSuccessful();

        $copy = Company::query()->where('name', 'Command Line Ltd')->whereKeyNot($this->company->id)->sole();

        expect($copy->owner()?->is($this->owner))->toBeTrue();
    });

    it('compares every restored row with the backup when asked to verify', function () {
        $this->artisan('backup:import', ['file' => $this->zip, '--owner' => $this->owner->id, '--verify' => true])
            ->expectsOutputToContain('Every row restored')
            ->expectsOutputToContain('verified.')
            ->assertSuccessful();

        expect(Company::query()->where('name', 'Command Line Ltd')->count())->toBe(2)
            ->and(CompanyRestore::query()->sole()->status)->toBe(CompanyRestoreStatus::Completed);
    });

    it('refuses an owner it cannot find', function () {
        $this->artisan('backup:import', ['file' => $this->zip, '--owner' => 'nobody@example.test', '--sync' => true])
            ->expectsOutputToContain('No user with id or email [nobody@example.test]')
            ->assertFailed();

        expect(CompanyRestore::query()->count())->toBe(0);
    });
});
