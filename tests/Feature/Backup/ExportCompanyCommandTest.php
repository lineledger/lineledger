<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * `backup:export`: the in-app backup it has always made, and backups into a
 * directory for a nightly cron.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Nightly Books Ltd']);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->dir = Storage::disk('local')->path('nightly');
});

describe('in-app backups', function () {
    it('queues one for --company=<id>, as it always has', function () {
        Queue::fake();

        $this->artisan('backup:export', ['--company' => $this->company->id])
            ->expectsOutputToContain('queued')
            ->assertSuccessful();

        expect(CompanyBackup::query()->withoutGlobalScopes()->sole()->status)->toBe(CompanyBackupStatus::Pending);
        Queue::assertPushed(ExportCompanyDataJob::class);
    });

    it('makes one here with --sync, for an organization named by slug, and verifies it', function () {
        $this->artisan('backup:export', ['companies' => [$this->company->slug], '--sync' => true, '--verify' => true])
            ->expectsOutputToContain('ready')
            ->expectsOutputToContain('verified.')
            ->assertSuccessful();

        $backup = CompanyBackup::query()->withoutGlobalScopes()->sole();

        expect($backup->status)->toBe(CompanyBackupStatus::Ready)
            ->and($backup->company_id)->toBe($this->company->id);
        Storage::disk($backup->storageDisk())->assertExists($backup->file_path);
    });
});

describe('backups into a directory', function () {
    it('writes a ZIP and its checksum, readable by the owner only, and no in-app backup', function () {
        $this->artisan('backup:export', ['companies' => [$this->company->slug], '--output' => $this->dir])
            ->assertSuccessful();

        $folder = $this->dir.'/'.$this->company->slug;
        $zips = glob($folder.'/*.zip');

        expect($zips)->toHaveCount(1)
            ->and(basename($zips[0]))->toMatch('/^nightly-books-ltd-\d{8}-\d{6}Z\.zip$/')
            ->and(file_get_contents($zips[0].'.sha256'))->toBe(hash_file('sha256', $zips[0]).'  '.basename($zips[0]).PHP_EOL)
            ->and(fileperms($zips[0]) & 0777)->toBe(0600)
            ->and(fileperms($folder) & 0777)->toBe(0700)
            ->and(glob($folder.'/.*.partial'))->toBe([])
            ->and(CompanyBackup::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('backs up every organization with --all, except deleted ones', function () {
        $second = Company::factory()->create(['name' => 'Second Set Of Books']);
        $deleted = Company::factory()->create(['name' => 'Closed Down Inc']);
        foreach ([$second, $deleted] as $company) {
            $company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
        }
        $deleted->delete();

        $this->artisan('backup:export', ['--all' => true, '--output' => $this->dir])->assertSuccessful();

        $folders = array_map('basename', glob($this->dir.'/*'));

        expect($folders)->toContain($this->company->slug, $second->slug)
            ->and($folders)->not->toContain($deleted->slug)
            ->and($folders)->toHaveCount(Company::query()->count());
    });

    it('verifies each new backup with --verify', function () {
        $this->artisan('backup:export', ['companies' => [$this->company->id], '--output' => $this->dir, '--verify' => true])
            ->expectsOutputToContain('Every row restored')
            ->expectsOutputToContain('verified.')
            ->assertSuccessful();
    });

    it('keeps only the newest backups with --keep, touching nothing else', function () {
        $folder = $this->dir.'/'.$this->company->slug;
        mkdir($folder, 0700, true);

        foreach (['20240101-020000Z', '20240102-020000Z', '20240103-020000Z'] as $stamp) {
            touch("{$folder}/nightly-books-ltd-{$stamp}.zip");
            touch("{$folder}/nightly-books-ltd-{$stamp}.zip.sha256");
        }
        touch("{$folder}/notes.txt");
        touch("{$folder}/someone-else-20200101-000000Z.zip");

        $this->artisan('backup:export', ['companies' => [$this->company->slug], '--output' => $this->dir, '--keep' => 2])
            ->expectsOutputToContain('removed old backup nightly-books-ltd-20240101-020000Z.zip')
            ->assertSuccessful();

        $left = array_map('basename', glob($folder.'/*'));

        expect($left)->toContain('nightly-books-ltd-20240103-020000Z.zip', 'nightly-books-ltd-20240103-020000Z.zip.sha256', 'notes.txt', 'someone-else-20200101-000000Z.zip')
            ->and($left)->not->toContain('nightly-books-ltd-20240102-020000Z.zip', 'nightly-books-ltd-20240101-020000Z.zip', 'nightly-books-ltd-20240101-020000Z.zip.sha256')
            ->and(count(preg_grep('/^nightly-books-ltd-.*Z\.zip$/', $left)))->toBe(2);
    });

    it('carries on past an organization it cannot back up, and says so in its exit code', function () {
        $ownerless = Company::factory()->create(['name' => 'Nobody Owns This']);

        $this->artisan('backup:export', ['companies' => [$ownerless->slug, $this->company->slug], '--output' => $this->dir])
            ->expectsOutputToContain('has no owner')
            ->assertFailed();

        expect(glob($this->dir.'/'.$this->company->slug.'/*.zip'))->toHaveCount(1);
    });
});

it('refuses to run when :dataset', function (array $arguments, string $message) {
    $this->artisan('backup:export', $arguments)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(CompanyBackup::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no organization is named' => [[], 'Name an organization (id or slug), or pass --all.'],
    'an organization does not exist' => [['companies' => ['no-such-books']], 'No organization with id or slug [no-such-books].'],
    'organizations are named along with --all' => [['companies' => ['x'], '--all' => true], 'not both'],
    '--keep comes without --output' => [['--all' => true, '--keep' => 3], '--keep needs --output'],
    '--keep is not a positive number' => [['--all' => true, '--output' => '/tmp', '--keep' => 0], '--keep needs --output'],
    'it would verify a backup still in the queue' => [['--all' => true, '--verify' => true], '--verify needs --output or --sync'],
]);
