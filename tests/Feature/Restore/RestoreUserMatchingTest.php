<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\UserRemapBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * backup.restore_user_matching (RESTORE_USER_MATCHING): 'instance' matches a
 * backup's users to anyone on the server by email, as always; 'restorer'
 * matches only the person restoring, so on a server shared by unrelated
 * customers a crafted backup can't confirm who else has an account, show
 * their names, or make them members of the copy.
 */

beforeEach(function () {
    $this->restorer = User::factory()->create(['email' => 'restorer@example.com']);
    $this->stranger = User::factory()->create(['name' => 'Someone Else', 'email' => 'stranger@example.com']);

    $this->bundleUsers = [
        ['id' => 11, 'email' => 'Restorer@Example.com', 'name' => 'Restorer in the bundle'],
        ['id' => 22, 'email' => 'stranger@example.com', 'name' => 'Typed by an attacker'],
        ['id' => 33, 'email' => 'nobody@example.com', 'name' => 'Nobody'],
    ];
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('matches anyone on the server by default, exactly as before', function () {
    expect(config('backup.restore_user_matching'))->toBe('instance');

    $result = (new UserRemapBuilder)->build($this->bundleUsers, $this->restorer);

    expect($result['map'])->toBe([
        11 => $this->restorer->id,
        22 => $this->stranger->id,
        33 => $this->restorer->id,
    ])->and(array_column($result['matches'], 'match'))->toBe(['email', 'email', 'fallback']);
});

it('matches only the person restoring when set to restorer', function () {
    config(['backup.restore_user_matching' => 'restorer']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = (new UserRemapBuilder)->build($this->bundleUsers, $this->restorer);

    expect($result['map'])->toBe([
        11 => $this->restorer->id,
        22 => $this->restorer->id,
        33 => $this->restorer->id,
    ])->and(array_column($result['matches'], 'match'))->toBe(['email', 'fallback', 'fallback'])
        // Nothing about any other account was looked up.
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'users')))->toBe([]);
});

it('never matches someone else in restorer mode, even when the restorer is not in the backup', function () {
    config(['backup.restore_user_matching' => 'restorer']);

    $result = (new UserRemapBuilder)->build([
        ['id' => 5, 'email' => 'stranger@example.com', 'name' => 'Someone Else'],
    ], $this->restorer);

    expect($result['map'])->toBe([5 => $this->restorer->id])
        ->and($result['matches'][0]['match'])->toBe('fallback');
});

it('keeps another customer out of a restored copy in restorer mode', function (string $mode, bool $strangerJoins) {
    config(['backup.restore_user_matching' => $mode]);
    Storage::fake('local');
    Storage::fake('public');

    // A backup in which the stranger was a member, as a crafted one could claim.
    $source = Company::factory()->create(['name' => 'Crafted Ltd']);
    Membership::create(['company_id' => $source->id, 'user_id' => $this->restorer->id, 'role' => CompanyRole::Owner]);
    Membership::create(['company_id' => $source->id, 'user_id' => $this->stranger->id, 'role' => CompanyRole::Accountant]);
    app()->instance('current_company', $source);
    Contact::factory()->customer()->create(['display_name' => 'A customer']);

    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->restorer->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $this->restorer->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $backup->file_path,
    ]);

    app()->forgetInstance('current_company');
    app(CompanyImporter::class)->import($restore);
    $restore->refresh();

    $copy = Company::query()->findOrFail($restore->company_id);

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed)
        ->and($copy->members()->whereKey($this->stranger->id)->exists())->toBe($strangerJoins)
        ->and($copy->owner()?->is($this->restorer))->toBeTrue();
})->with([
    'instance' => ['instance', true],
    'restorer' => ['restorer', false],
]);
