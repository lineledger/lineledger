<?php

use App\Models\Company;
use App\Services\Restore\AttachmentImporter;
use App\Services\Restore\IdMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->company = Company::factory()->create();

    $this->workDir = sys_get_temp_dir().'/restore-attach-test-'.uniqid();
    mkdir($this->workDir, 0755, true);
    mkdir($this->workDir.'/files/attachments/contact/1', 0755, true);
});

afterEach(function () {
    if (isset($this->workDir) && is_dir($this->workDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->workDir);
    }
});

/**
 * Insert a raw `attachments` row whose `path` points at a bundle-relative
 * location (mirrors what RowTransformer leaves behind after replaying the
 * bundle's `attachments.jsonl`).
 *
 * @param  array<string, mixed>  $overrides
 */
function makeAttachmentRow(int $companyId, string $bundleRelativePath, array $overrides = []): int
{
    $row = array_merge([
        'company_id' => $companyId,
        'attachable_type' => 'contact',
        'attachable_id' => 1,
        'disk' => 'local',
        'path' => $bundleRelativePath,
        'original_filename' => basename($bundleRelativePath),
        'mime_type' => 'application/pdf',
        'size_bytes' => 0,
        'uploaded_by_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    return DB::table('attachments')->insertGetId($row);
}

it('copies attachment blobs onto the target disk and updates the rows', function () {
    $bundlePathA = 'files/attachments/contact/1/__ID__a.pdf';
    $bundlePathB = 'files/attachments/contact/1/__ID__b.pdf';

    $idA = makeAttachmentRow($this->company->id, $bundlePathA, ['original_filename' => 'a.pdf']);
    $idB = makeAttachmentRow($this->company->id, $bundlePathB, ['original_filename' => 'b.pdf']);

    // The bundle-relative paths on the rows match the on-disk locations under
    // the extracted dir.
    file_put_contents($this->workDir.'/'.$bundlePathA, 'AAA');
    file_put_contents($this->workDir.'/'.$bundlePathB, 'BBBBB');

    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result['copied'])->toBe(2)
        ->and($result['missing'])->toBe(0)
        ->and($result['substituted_disk'])->toBe(0)
        ->and($result['bytes'])->toBe(3 + 5)
        ->and($result['errors'])->toBe([]);

    $expectedPathA = "attachments/{$this->company->id}/{$idA}_a.pdf";
    $expectedPathB = "attachments/{$this->company->id}/{$idB}_b.pdf";

    Storage::disk('local')->assertExists($expectedPathA);
    Storage::disk('local')->assertExists($expectedPathB);

    expect(Storage::disk('local')->get($expectedPathA))->toBe('AAA')
        ->and(Storage::disk('local')->get($expectedPathB))->toBe('BBBBB');

    $rowA = DB::table('attachments')->where('id', $idA)->first();
    $rowB = DB::table('attachments')->where('id', $idB)->first();

    expect($rowA->disk)->toBe('local')
        ->and($rowA->path)->toBe($expectedPathA)
        ->and($rowB->disk)->toBe('local')
        ->and($rowB->path)->toBe($expectedPathB);
});

it('counts missing source files without failing the import', function () {
    $bundlePathPresent = 'files/attachments/contact/1/present.pdf';
    $bundlePathMissing = 'files/attachments/contact/1/missing.pdf';

    $idPresent = makeAttachmentRow($this->company->id, $bundlePathPresent, ['original_filename' => 'present.pdf']);
    $idMissing = makeAttachmentRow($this->company->id, $bundlePathMissing, ['original_filename' => 'missing.pdf']);

    file_put_contents($this->workDir.'/'.$bundlePathPresent, 'PRESENT');
    // Note: $bundlePathMissing is intentionally NOT written.

    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result['copied'])->toBe(1)
        ->and($result['missing'])->toBe(1)
        ->and($result['errors'])->toBe([]);

    Storage::disk('local')->assertExists("attachments/{$this->company->id}/{$idPresent}_present.pdf");

    // The missing row was left alone — its path still points at the bundle.
    $missingRow = DB::table('attachments')->where('id', $idMissing)->first();
    expect($missingRow->path)->toBe($bundlePathMissing);
});

it('routes a bundle from another disk onto this install and tallies the substitution', function () {
    expect(config('filesystems.disks.s3-imaginary'))->toBeNull();

    $bundlePath = 'files/attachments/contact/1/from-s3.pdf';
    $id = makeAttachmentRow(
        $this->company->id,
        $bundlePath,
        ['disk' => 's3-imaginary', 'original_filename' => 'from-s3.pdf'],
    );

    file_put_contents($this->workDir.'/'.$bundlePath, 'CLOUDY');

    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result['copied'])->toBe(1)
        ->and($result['substituted_disk'])->toBe(1);

    $row = DB::table('attachments')->where('id', $id)->first();
    expect($row->disk)->toBe('local')
        ->and($row->path)->toBe("attachments/{$this->company->id}/{$id}_from-s3.pdf");

    Storage::disk('local')->assertExists("attachments/{$this->company->id}/{$id}_from-s3.pdf");
});

it('imports a local bundle onto object storage when that is what this install uses', function () {
    Storage::fake('s3');
    config()->set('filesystems.roles.attachments', 's3');

    $bundlePath = 'files/attachments/contact/1/from-local.pdf';
    $id = makeAttachmentRow(
        $this->company->id,
        $bundlePath,
        ['disk' => 'local', 'original_filename' => 'from-local.pdf'],
    );

    file_put_contents($this->workDir.'/'.$bundlePath, 'ONPREM');

    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result['copied'])->toBe(1)
        ->and($result['substituted_disk'])->toBe(1);

    $target = "attachments/{$this->company->id}/{$id}_from-local.pdf";

    // The bundle's recorded disk is history, not an instruction: a restore must
    // not scatter fresh files back onto the local filesystem.
    expect(DB::table('attachments')->where('id', $id)->first()->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($target);
    Storage::disk('local')->assertMissing($target);
});

it('does not tally a substitution when the bundle already matches this install', function () {
    $bundlePath = 'files/attachments/contact/1/same-disk.pdf';
    makeAttachmentRow(
        $this->company->id,
        $bundlePath,
        ['disk' => 'local', 'original_filename' => 'same-disk.pdf'],
    );

    file_put_contents($this->workDir.'/'.$bundlePath, 'SAME');

    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result['copied'])->toBe(1)
        ->and($result['substituted_disk'])->toBe(0);
});

it('sanitizes hostile original_filename values so traversal is impossible', function () {
    $bundlePath = 'files/attachments/contact/1/safe-source.pdf';
    $id = makeAttachmentRow(
        $this->company->id,
        $bundlePath,
        ['original_filename' => '../../etc/passwd'],
    );

    file_put_contents($this->workDir.'/'.$bundlePath, 'SECRETS');

    (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    $row = DB::table('attachments')->where('id', $id)->first();

    expect($row->path)->toStartWith("attachments/{$this->company->id}/{$id}_")
        ->and($row->path)->not->toContain('..')
        ->and(str_contains($row->path, '/etc/'))->toBeFalse();

    // And the file lives where the column claims it does.
    Storage::disk('local')->assertExists($row->path);
});

it('refuses a bundle path that escapes the extracted dir via traversal', function () {
    // A secret file sitting OUTSIDE the extracted bundle (a stand-in for
    // `.env`, another tenant's blob, etc.).
    $secret = sys_get_temp_dir().'/restore-secret-'.uniqid().'.txt';
    file_put_contents($secret, 'TOP-SECRET-APP-KEY');

    try {
        // `files/../../<secret>` starts with the legit `files/` root but climbs
        // back out of the bundle to the secret. Pre-fix, `ltrim('/')` left the
        // `..` intact and the file was copied into the attacker's company store.
        $hostilePath = 'files/../../'.basename($secret);
        $id = makeAttachmentRow($this->company->id, $hostilePath, ['original_filename' => 'loot.txt']);

        // Sanity: the traversal really does resolve to the secret on disk.
        expect(realpath($this->workDir.'/'.$hostilePath))->toBe(realpath($secret));

        $result = (new AttachmentImporter)->importAttachments(
            $this->company->id,
            $this->workDir,
            new IdMapper,
        );

        // Rejected → counted as missing, nothing copied.
        expect($result['copied'])->toBe(0)
            ->and($result['missing'])->toBe(1);

        // The secret's bytes never landed in the company's attachment store.
        expect(Storage::disk('local')->allFiles("attachments/{$this->company->id}"))->toBe([]);

        // The row was left untouched (still points at the bundle path).
        $row = DB::table('attachments')->where('id', $id)->first();
        expect($row->path)->toBe($hostilePath);
    } finally {
        @unlink($secret);
    }
});

it('refuses an absolute bundle path', function () {
    $secret = sys_get_temp_dir().'/restore-secret-abs-'.uniqid().'.txt';
    file_put_contents($secret, 'TOP-SECRET');

    try {
        $id = makeAttachmentRow($this->company->id, $secret, ['original_filename' => 'loot.txt']);

        $result = (new AttachmentImporter)->importAttachments(
            $this->company->id,
            $this->workDir,
            new IdMapper,
        );

        expect($result['copied'])->toBe(0)
            ->and($result['missing'])->toBe(1)
            ->and(Storage::disk('local')->allFiles("attachments/{$this->company->id}"))->toBe([]);

        $row = DB::table('attachments')->where('id', $id)->first();
        expect($row->path)->toBe($secret);
    } finally {
        @unlink($secret);
    }
});

it('imports a company logo from the bundle onto the public disk', function () {
    mkdir($this->workDir.'/files/company-logo', 0755, true);
    file_put_contents($this->workDir.'/files/company-logo/logo.png', 'PNG-BYTES');

    $returned = (new AttachmentImporter)->importCompanyLogo($this->company->id, $this->workDir);

    expect($returned)->toBe('company-logos/logo.png');
    Storage::disk('public')->assertExists('company-logos/logo.png');
    expect(Storage::disk('public')->get('company-logos/logo.png'))->toBe('PNG-BYTES');

    $fresh = DB::table('companies')->where('id', $this->company->id)->first();
    expect($fresh->logo_path)->toBe('company-logos/logo.png');
});

it('imports a company document logo from the bundle onto the public disk', function () {
    mkdir($this->workDir.'/files/company-document-logo', 0755, true);
    file_put_contents($this->workDir.'/files/company-document-logo/doc.png', 'DOC-PNG-BYTES');

    $returned = (new AttachmentImporter)->importCompanyDocumentLogo($this->company->id, $this->workDir);

    expect($returned)->toBe('company-logos/doc.png');
    Storage::disk('public')->assertExists('company-logos/doc.png');
    expect(Storage::disk('public')->get('company-logos/doc.png'))->toBe('DOC-PNG-BYTES');

    $fresh = DB::table('companies')->where('id', $this->company->id)->first();
    expect($fresh->document_logo_path)->toBe('company-logos/doc.png');
});

it('returns null when the bundle has no company logo', function () {
    expect((new AttachmentImporter)->importCompanyLogo($this->company->id, $this->workDir))
        ->toBeNull();

    $fresh = DB::table('companies')->where('id', $this->company->id)->first();
    expect($fresh->logo_path)->toBeNull();
});

it('returns a zeroed summary when the new company has no attachment rows', function () {
    $result = (new AttachmentImporter)->importAttachments(
        $this->company->id,
        $this->workDir,
        new IdMapper,
    );

    expect($result)->toBe([
        'copied' => 0,
        'missing' => 0,
        'bytes' => 0,
        'substituted_disk' => 0,
        'errors' => [],
    ]);
});
