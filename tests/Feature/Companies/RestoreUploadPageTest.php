<?php

use App\Enums\CompanyRestoreStatus;
use App\Models\CompanyRestore;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Builds a minimal but BundleInspector-compatible ZIP and returns it as a
 * Livewire-friendly fake UploadedFile. The manifest matches the current
 * schema_version + a sample table, plus the empty users.json + companies.jsonl
 * the inspector needs.
 */
function makeValidBackupZip(): File
{
    $tmp = tempnam(sys_get_temp_dir(), 'restore-test-');
    @unlink($tmp);
    $path = $tmp.'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    $manifest = [
        'schema_version' => (int) config('version.schema'),
        'app_version' => (string) config('version.app'),
        'exported_at' => '2026-05-28T00:00:00Z',
        'company' => ['id' => 99, 'name' => 'Round-trip Co.', 'slug' => 'round-trip-co'],
        'tables' => [
            'accounts' => ['rows' => 5, 'sha256' => str_repeat('a', 64)],
        ],
        'files' => ['count' => 0, 'total_bytes' => 0, 'missing_count' => 0],
        'exclusions' => [],
    ];

    $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $zip->addFromString('users.json', json_encode([], JSON_THROW_ON_ERROR));
    $zip->addFromString(
        'data/companies.jsonl',
        json_encode(['id' => 99, 'name' => 'Round-trip Co.', 'slug' => 'round-trip-co'], JSON_THROW_ON_ERROR)."\n",
    );
    $zip->addFromString('data/accounts.jsonl', '');

    $zip->close();

    // Wrap the real ZIP bytes in a Livewire-compatible fake. UploadedFile::fake()
    // returns Illuminate\Http\Testing\File, which Livewire's WithFileUploads
    // expects (Livewire reads $file->name etc. as a magic property).
    return UploadedFile::fake()->createWithContent('backup.zip', file_get_contents($path));
}

it('redirects unauthenticated users to login', function () {
    $this->get(route('companies.restore'))
        ->assertRedirect(route('login'));
});

it('renders the upload page for authenticated users', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('companies.restore'))
        ->assertOk()
        ->assertSeeText('Restore from backup')
        ->assertSeeText('Drag & drop your backup ZIP here');
});

it('renders the upload page for a user who has no company yet', function () {
    // The onboarding wizard sends company-less users straight here when they
    // choose "Restore from a backup". Two things must hold: EnsureUserHasCompany
    // must exempt this route (else the redirect bounces them to the wizard's
    // start), and the page must use the onboarding layout (the default app
    // layout renders a company-scoped sidebar whose route('dashboard') link
    // throws when there is no current company).
    //
    // make()->save() deliberately skips the factory's afterCreating hook, which
    // would otherwise create a personal company AND leak a `company` URL default
    // via switchCompany() — that default would let route('dashboard') resolve
    // and mask the very failure this test guards against. This mirrors a real
    // onboarding user who has no company at all.
    $user = User::factory()->make();
    $user->save();

    // make()->save() also skips the legal acceptance the factory normally seeds;
    // this test is about the company-less restore flow, not the legal gate, so
    // record it so EnsureLegalAcceptance doesn't bounce the user to /legal/accept.
    app(LegalDocuments::class)->record($user, ['terms', 'privacy']);

    $this->actingAs($user)
        ->get(route('companies.restore'))
        ->assertOk()
        ->assertSeeText('Restore from backup');
});

it('rejects a non-zip upload with a validation error', function () {
    $this->actingAs(User::factory()->create());

    $bad = UploadedFile::fake()->create('notes.txt', 5, 'text/plain');

    Livewire::test('pages::companies.restore')
        ->set('upload', $bad)
        ->call('inspect')
        ->assertHasErrors(['upload'])
        ->assertSet('mode', 'upload');
});

it('rejects an oversize zip upload', function () {
    $this->actingAs(User::factory()->create());

    // 100 MB max → 102400 KB. 102401 KB tips over the validation limit.
    $tooBig = UploadedFile::fake()->create('giant.zip', 102401, 'application/zip');

    Livewire::test('pages::companies.restore')
        ->set('upload', $tooBig)
        ->call('inspect')
        ->assertHasErrors(['upload'])
        ->assertSet('mode', 'upload');
});

it('transitions to preview mode and creates a Ready restore row on a happy upload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $zip = makeValidBackupZip();

    Livewire::test('pages::companies.restore')
        ->set('upload', $zip)
        ->call('inspect')
        ->assertHasNoErrors()
        ->assertSet('mode', 'preview')
        ->assertSet('uploadError', null)
        // store() moved the temp file into the CompanyRestore row, so the stale
        // upload handle must be cleared (re-validating it would 500 on getSize).
        ->assertSet('upload', null);

    $restore = CompanyRestore::query()->where('requested_by_user_id', $user->id)->first();

    expect($restore)->not->toBeNull()
        ->and($restore->status)->toBe(CompanyRestoreStatus::Ready)
        ->and($restore->file_path)->toStartWith('restores/')
        ->and($restore->sha256)->toBeString()
        ->and(strlen((string) $restore->sha256))->toBe(64)
        ->and($restore->manifest_data)->toBeArray();
});

it('keeps the user in upload mode and surfaces the error when the inspector rejects the bundle', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // A well-formed ZIP that the real BundleInspector will reject (no
    // manifest.json) exercises the BundleValidationException catch branch
    // end-to-end. BundleInspector is final, so mocking is out — using the
    // real service against a deliberately-invalid bundle is the more honest
    // test anyway.
    $tmp = tempnam(sys_get_temp_dir(), 'invalid-restore-').'.zip';
    $zipArchive = new ZipArchive;
    $zipArchive->open($tmp, ZipArchive::CREATE);
    $zipArchive->addFromString('readme.txt', 'not a backup');
    $zipArchive->close();
    $invalidZip = UploadedFile::fake()->createWithContent('not-a-backup.zip', file_get_contents($tmp));

    Livewire::test('pages::companies.restore')
        ->set('upload', $invalidZip)
        ->call('inspect')
        ->assertHasNoErrors()
        ->assertSet('mode', 'upload')
        // The rejected upload's temp file was moved away by store(); clearing the
        // handle stops a second "Inspect" click from 500ing on getSize().
        ->assertSet('upload', null);

    // The orphaned file should be cleaned up, and no CompanyRestore row written.
    expect(CompanyRestore::query()->where('requested_by_user_id', $user->id)->count())->toBe(0);
});
