<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\BackupRoundTrip;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $owner = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Verify Me Ltd']);
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $this->zip = BackupRoundTrip::bundle($this->company);
});

it('verifies a sound backup, trial restore and all', function () {
    $this->artisan('backup:verify', ['files' => [$this->zip]])
        ->expectsOutputToContain('Restore completed')
        ->expectsOutputToContain('Every row restored')
        ->expectsOutputToContain('verified.')
        ->assertSuccessful();
});

it('checks only the bundle with --quick', function () {
    $this->artisan('backup:verify', ['files' => [$this->zip], '--quick' => true])
        ->expectsOutputToContain('Audit chain intact')
        ->doesntExpectOutputToContain('Every row restored')
        ->assertSuccessful();
});

it('fails, and says why, for a damaged backup', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => unlink($dir.'/data/accounts.jsonl'));

    $this->artisan('backup:verify', ['files' => [$this->zip, $damaged]])
        ->expectsOutputToContain('data/accounts.jsonl is missing')
        ->expectsOutputToContain('FAILED verification')
        ->assertFailed();
});

it('fails for a file that is not there', function () {
    $this->artisan('backup:verify', ['files' => ['/nonexistent/backup.zip']])
        ->expectsOutputToContain('Backup file not found')
        ->assertFailed();
});

it('reports as JSON', function () {
    $this->withoutMockingConsoleOutput();
    Artisan::call('backup:verify', ['files' => [$this->zip], '--json' => true]);

    $reports = json_decode(Artisan::output(), true);

    expect($reports)->toHaveCount(1)
        ->and($reports[0]['passed'])->toBeTrue()
        ->and($reports[0]['file'])->toBe($this->zip)
        ->and(array_column($reports[0]['checks'], 'name'))->toContain('Every row restored', 'Ledger integrity')
        ->and($reports[0]['tables'])->toHaveKey('journal_entries');
});
