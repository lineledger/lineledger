<?php

use App\Enums\CompanyRole;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * `storage:check` is what an operator runs after pointing the app at a bucket,
 * so its failure paths matter more than its happy path.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('passes on a default local install', function () {
    $this->artisan('storage:check')->assertExitCode(0);
});

it('fails when a role points at a disk that does not exist', function () {
    config()->set('filesystems.roles.attachments', 'not-a-disk');

    $this->artisan('storage:check')
        ->expectsOutputToContain('not-a-disk')
        ->assertExitCode(1);
});

it('fails when Livewire would stage uploads somewhere without a real path', function () {
    // getRealPath() on a TemporaryUploadedFile only works on a local disk; the
    // CSV and migration importers depend on it.
    config()->set('filesystems.disks.pretend-s3', ['driver' => 's3']);
    config()->set('livewire.temporary_file_upload.disk', 'pretend-s3');

    $this->artisan('storage:check')
        ->expectsOutputToContain('local disk')
        ->assertExitCode(1);
});

function makeAttachmentOnDisk(Company $company, string $disk): Attachment
{
    return Attachment::create([
        'attachable_type' => $company->getMorphClass(),
        'attachable_id' => $company->id,
        'disk' => $disk,
        'path' => 'attachments/'.$company->id.'/probe.pdf',
        'original_filename' => 'probe.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1,
    ]);
}

it('flags stored files that reference a disk no longer in the config', function () {
    makeAttachmentOnDisk($this->company, 'retired-disk');

    $this->artisan('storage:check')
        ->expectsOutputToContain('retired-disk')
        ->assertExitCode(1);
});

it('treats an empty disk value as local rather than an orphan', function () {
    // Rows predating the per-row disk column carry no value.
    makeAttachmentOnDisk($this->company, '');

    $this->artisan('storage:check')->assertExitCode(0);
});

it('warns when a Canadian deployment stores files outside Canada', function () {
    config()->set('app.region', 'CA');
    config()->set('filesystems.roles.attachments', 's3');
    config()->set('filesystems.disks.s3.region', 'us-east-1');
    Storage::fake('s3');

    $this->artisan('storage:check', ['--skip-probes' => true])
        ->expectsOutputToContain('ca-central-1');
});

it('skips the live probes when asked', function () {
    $this->artisan('storage:check', ['--skip-probes' => true])
        ->doesntExpectOutputToContain('Round trip')
        ->assertExitCode(0);
});
