<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Membership;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use App\Support\Gifi\GifiCodeCorrections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * A backup made before the GIFI codes were corrected still carries the old,
 * mislabelled codes. Its manifest has no `gifi_codes` revision, so the restore
 * corrects them as the migration did; a current backup restores untouched.
 */

function gifiRestoreBackup(Company $company, User $owner): CompanyBackup
{
    app()->instance('current_company', $company);

    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);

    app(CompanyExporter::class)->export($backup);

    return $backup->refresh();
}

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $edit
 */
function gifiRestoreEditManifest(CompanyBackup $backup, callable $edit): void
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($backup->file_path));
    $manifest = $edit(json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR));
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();
}

function gifiRestoreRun(CompanyBackup $backup, User $owner): CompanyRestore
{
    $restorePath = 'restores/'.basename($backup->file_path);
    Storage::disk('local')->put($restorePath, Storage::disk('local')->get($backup->file_path));

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restorePath,
    ]);

    app()->forgetInstance('current_company');
    app(CompanyImporter::class)->import($restore);

    return $restore->refresh();
}

function gifiRestoredCode(CompanyRestore $restore, string $accountCode): ?string
{
    return Account::withoutGlobalScopes()
        ->where('company_id', $restore->company_id)
        ->where('code', $accountCode)
        ->value('gifi_code');
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->create(['email' => 'owner-gifi@acme.test']);
    $this->company = Company::factory()->create(['name' => 'GIFI Restore Co', 'address_country' => 'CA']);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);
    app()->instance('current_company', $this->company);

    // An expense account saved as "8620 Insurance" under the old catalog, and
    // one on a code that never changed.
    $expenses = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->take(2)->get();
    $this->insurance = $expenses[0];
    $this->other = $expenses[1];
    DB::table('accounts')->where('id', $this->insurance->id)->update(['gifi_code' => '8620']);
    DB::table('accounts')->where('id', $this->other->id)->update(['gifi_code' => '9270']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('records the GIFI code revision in a new backup', function () {
    $backup = gifiRestoreBackup($this->company, $this->owner);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($backup->file_path));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();

    expect($manifest['gifi_codes'])->toBe(GifiCodeCorrections::REVISION);
});

it('corrects the GIFI codes of a backup made before the correction', function () {
    $backup = gifiRestoreBackup($this->company, $this->owner);
    gifiRestoreEditManifest($backup, function (array $manifest): array {
        unset($manifest['gifi_codes']);

        return $manifest;
    });

    $restore = gifiRestoreRun($backup, $this->owner);

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message)
        ->and(gifiRestoredCode($restore, $this->insurance->code))->toBe('8690')
        ->and(gifiRestoredCode($restore, $this->other->code))->toBe('9270');
});

it('leaves the GIFI codes of a current backup as they are', function () {
    $backup = gifiRestoreBackup($this->company, $this->owner);

    $restore = gifiRestoreRun($backup, $this->owner);

    // 8620 is Employee benefits now; a current backup means exactly that.
    expect($restore->status)->toBe(CompanyRestoreStatus::Completed, (string) $restore->error_message)
        ->and(gifiRestoredCode($restore, $this->insurance->code))->toBe('8620')
        ->and(gifiRestoredCode($restore, $this->other->code))->toBe('9270');
});
