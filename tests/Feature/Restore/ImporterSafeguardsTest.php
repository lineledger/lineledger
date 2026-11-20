<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\NavPreference;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\RestoreTrace;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\BackupRoundTrip;

/**
 * What a restore refuses, and what it deliberately leaves out.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Safeguards Ltd']);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
});

it('refuses a backup missing a table file its manifest lists', function () {
    $damaged = BackupRoundTrip::rewrite(BackupRoundTrip::bundle($this->company), fn (string $dir) => unlink($dir.'/data/contacts.jsonl'));
    $companies = Company::withTrashed()->count();

    expect(fn () => BackupRoundTrip::restore($damaged, $this->owner))
        ->toThrow(BundleValidationException::class, 'its manifest lists table contacts, but data/contacts.jsonl is missing');

    expect(Company::withTrashed()->count())->toBe($companies);
});

it('refuses a table file its manifest does not list', function () {
    $damaged = BackupRoundTrip::rewrite(BackupRoundTrip::bundle($this->company), fn (string $dir) => file_put_contents($dir.'/data/smuggled.jsonl', "{\"id\":1}\n"));

    expect(fn () => BackupRoundTrip::restore($damaged, $this->owner))
        ->toThrow(BundleValidationException::class, 'data/smuggled.jsonl is not listed in its manifest');
});

it('refuses a users file that does not match its recorded hash', function () {
    $damaged = BackupRoundTrip::rewrite(BackupRoundTrip::bundle($this->company), function (string $dir): void {
        $users = json_decode((string) file_get_contents($dir.'/users.json'), true);
        $users[0]['email'] = 'someone-else@example.test';
        file_put_contents($dir.'/users.json', json_encode($users));
    });

    expect(fn () => BackupRoundTrip::restore($damaged, $this->owner))
        ->toThrow(BundleValidationException::class, 'Integrity check failed for users.json');
});

it('restores a backup of a deleted organization as a live one', function () {
    $this->company->forceFill(['purge_at' => now()->addDays(30)])->save();
    $this->company->delete();

    $trace = BackupRoundTrip::restore(BackupRoundTrip::bundle(Company::withTrashed()->findOrFail($this->company->id)), $this->owner);
    $copy = Company::query()->findOrFail($trace->companyId);

    expect($copy->deleted_at)->toBeNull()
        ->and($copy->purge_at)->toBeNull();
});

it('leaves out the personal settings of people nobody here matches, so two of them never collide', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    foreach ([$this->owner, $alice, $bob] as $user) {
        $this->company->members()->syncWithoutDetaching([$user->id => ['role' => CompanyRole::Accountant->value]]);
        UserPreference::query()->forceCreate(['company_id' => $this->company->id, 'user_id' => $user->id, 'pref_key' => 'reports.columns', 'value' => ['name']]);
        NavPreference::factory()->create(['company_id' => $this->company->id, 'user_id' => $user->id, 'item_key' => 'sales']);
    }
    $this->company->members()->updateExistingPivot($this->owner->id, ['role' => CompanyRole::Owner->value]);

    $zip = BackupRoundTrip::bundle($this->company);

    // Elsewhere, nobody matches: every one of them would land on the importer.
    $elsewhere = BackupRoundTrip::restore($zip, User::factory()->create(), matchUsersByEmail: false);

    expect(UserPreference::query()->withoutGlobalScopes()->where('company_id', $elsewhere->companyId)->count())->toBe(0)
        ->and(NavPreference::query()->withoutGlobalScopes()->where('company_id', $elsewhere->companyId)->count())->toBe(0)
        ->and($elsewhere->skipped['user_preferences'])->toHaveCount(3);

    // Here, everyone matches and keeps their own.
    $here = BackupRoundTrip::restore($zip, $this->owner);

    expect(UserPreference::query()->withoutGlobalScopes()->where('company_id', $here->companyId)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->owner->id, $alice->id, $bob->id])->sort()->values()->all());
});

it('restores a JSON setting of every type exactly', function (mixed $value) {
    $source = UserPreference::query()->forceCreate(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'pref_key' => 'a.setting', 'value' => $value]);

    $trace = BackupRoundTrip::restore(BackupRoundTrip::bundle($this->company), $this->owner);
    $restored = UserPreference::query()->withoutGlobalScopes()->where('company_id', $trace->companyId)->where('pref_key', 'a.setting')->firstOrFail()->value;

    // Against the original as the database hands it back (MySQL orders a JSON
    // object's keys its own way), and strictly: false is not 0.
    expect($restored)->toBe($source->fresh()->value)
        ->and($restored)->toEqual($value);
})->with([
    'a string' => ['abc'],
    'false' => [false],
    'true' => [true],
    'a number' => [42],
    'a list' => [[1, 2, 3]],
    'an object' => [['columns' => ['name', 'total'], 'dense' => true]],
]);

it('puts back the organization a queue worker had bound before an export', function () {
    $other = Company::factory()->create();
    $backup = CompanyBackup::query()->forceCreate([
        'company_id' => $this->company->id,
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->owner->id,
    ]);

    app()->instance('current_company', $other);
    (new ExportCompanyDataJob($backup))->handle(app(CompanyExporter::class));
    expect(app('current_company')->is($other))->toBeTrue();

    app()->forgetInstance('current_company');
    $again = $backup->replicate()->fill(['status' => CompanyBackupStatus::Pending]);
    $again->save();
    (new ExportCompanyDataJob($again))->handle(app(CompanyExporter::class));
    expect(app()->bound('current_company'))->toBeFalse();
});

it('marks a synchronous restore failed, not stuck running, when its bundle is gone', function () {
    $restore = CompanyRestore::create([
        'requested_by_user_id' => $this->owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'disk' => 'local',
        'file_path' => 'restores/vanished.zip',
    ]);

    expect(fn () => app(CompanyImporter::class)->import($restore, new RestoreTrace))
        ->toThrow(RuntimeException::class, 'Restore bundle missing');

    expect($restore->fresh()->status)->toBe(CompanyRestoreStatus::Failed);
});
