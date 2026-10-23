<?php

use App\Jobs\RestoreCompanyDataJob;
use App\Models\CompanyRestore;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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
