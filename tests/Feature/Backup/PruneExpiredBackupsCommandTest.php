<?php

use App\Enums\CompanyBackupStatus;
use App\Models\Company;
use App\Models\CompanyBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Retention sweep verifies three states:
 *
 *  - expired-but-still-Ready row: file is deleted, status flips to Expired,
 *    file_path / file_size_bytes / sha256 nulled so the UI hides the
 *    download button. The row stays as audit history.
 *  - already-Expired row that's already been cleaned: untouched (no double
 *    work, no spurious "pruned" log line).
 *  - still-valid Ready row (expires_at in the future): untouched.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->company = Company::factory()->create();
});

it('prunes expired Ready backups, leaves valid ones alone, and skips already-cleaned Expired rows', function () {
    Storage::disk('local')->put('backups/expired-ready.zip', 'data-expired');
    Storage::disk('local')->put('backups/still-valid.zip', 'data-valid');

    $expiredReady = CompanyBackup::factory()->for($this->company)->create([
        'status' => CompanyBackupStatus::Ready,
        'file_path' => 'backups/expired-ready.zip',
        'file_size_bytes' => 12,
        'sha256' => str_repeat('a', 64),
        'expires_at' => now()->subDay(),
    ]);

    $stillValid = CompanyBackup::factory()->for($this->company)->create([
        'status' => CompanyBackupStatus::Ready,
        'file_path' => 'backups/still-valid.zip',
        'file_size_bytes' => 11,
        'sha256' => str_repeat('b', 64),
        'expires_at' => now()->addDays(6),
    ]);

    $alreadyExpired = CompanyBackup::factory()->for($this->company)->create([
        'status' => CompanyBackupStatus::Expired,
        'file_path' => null,
        'file_size_bytes' => null,
        'sha256' => null,
        'expires_at' => now()->subDays(10),
    ]);

    Artisan::call('backups:prune-expired');

    $expiredReady->refresh();
    expect($expiredReady->status)->toBe(CompanyBackupStatus::Expired)
        ->and($expiredReady->file_path)->toBeNull()
        ->and($expiredReady->file_size_bytes)->toBeNull()
        ->and($expiredReady->sha256)->toBeNull();

    Storage::disk('local')->assertMissing('backups/expired-ready.zip');

    $stillValid->refresh();
    expect($stillValid->status)->toBe(CompanyBackupStatus::Ready)
        ->and($stillValid->file_path)->toBe('backups/still-valid.zip')
        ->and($stillValid->file_size_bytes)->toBe(11);

    Storage::disk('local')->assertExists('backups/still-valid.zip');

    $alreadyExpired->refresh();
    expect($alreadyExpired->status)->toBe(CompanyBackupStatus::Expired)
        ->and($alreadyExpired->file_path)->toBeNull()
        ->and($alreadyExpired->file_size_bytes)->toBeNull();
});

it('reports a summary line including the number pruned and bytes freed', function () {
    Storage::disk('local')->put('backups/sweep-me.zip', str_repeat('x', 100));

    CompanyBackup::factory()->for($this->company)->create([
        'status' => CompanyBackupStatus::Ready,
        'file_path' => 'backups/sweep-me.zip',
        'file_size_bytes' => 100,
        'sha256' => str_repeat('c', 64),
        'expires_at' => now()->subHour(),
    ]);

    Artisan::call('backups:prune-expired');

    $output = Artisan::output();

    expect($output)->toContain('Pruned 1 backup')
        ->and($output)->toContain('100');
});
