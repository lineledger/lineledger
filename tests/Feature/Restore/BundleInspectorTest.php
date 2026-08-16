<?php

use App\Models\User;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\UserRemapBuilder;

beforeEach(function () {
    $this->workDir = sys_get_temp_dir().'/restore-inspector-test-'.uniqid();
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->workDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->workDir);
    }
});

/**
 * Build a fake bundle ZIP at `$path` from `[localName => contents]`.
 * Uses ZipArchive::CREATE + OVERWRITE so each test gets a fresh archive.
 */
function buildFakeBundle(string $path, array $entries): void
{
    $zip = new ZipArchive;
    $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException("Failed to create fake bundle at {$path}");
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
}

function defaultManifest(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => (int) config('version.schema'),
        'app_version' => (string) config('version.app'),
        'exported_at' => '2026-05-27T18:00:00Z',
        'exported_by' => ['user_id' => 42, 'email' => 'exporter@example.com'],
        'company' => ['id' => 7, 'name' => 'Acme Inc.', 'slug' => 'acme-inc'],
        'tables' => [
            'companies' => ['rows' => 1, 'sha256' => 'a', 'bytes' => 10],
            'accounts' => ['rows' => 25, 'sha256' => 'b', 'bytes' => 100],
            'invoices' => ['rows' => 8, 'sha256' => 'c', 'bytes' => 50],
            'invoice_lines' => ['rows' => 30, 'sha256' => 'd', 'bytes' => 150],
            'journal_entries' => ['rows' => 12, 'sha256' => 'e', 'bytes' => 70],
        ],
        'files' => ['count' => 3, 'total_bytes' => 1024, 'missing_count' => 0],
        'users' => ['count' => 2, 'sha256' => 'u'],
        'exclusions' => [],
        'import_hints' => [],
    ], $overrides);
}

it('returns a preview struct on the happy path', function () {
    $importer = User::factory()->create(['email' => 'importer@example.com']);
    User::factory()->create(['email' => 'matched@example.com']);

    $zipPath = $this->workDir.'/happy.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => json_encode(defaultManifest()),
        'users.json' => json_encode([
            ['id' => 1, 'email' => 'matched@example.com', 'name' => 'Matched'],
            ['id' => 2, 'email' => 'unknown@example.com', 'name' => 'Unknown'],
        ]),
        'data/companies.jsonl' => json_encode([
            'id' => 7, 'name' => 'Acme Inc.', 'slug' => 'acme-inc',
        ])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);
    $result = $inspector->inspect($zipPath, $importer);

    expect($result['company'])->toBe([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
        'source_id' => 7,
    ]);

    expect($result['row_counts'])->toMatchArray([
        'companies' => 1,
        'accounts' => 25,
        'invoices' => 8,
        'invoice_lines' => 30,
        'journal_entries' => 12,
    ]);

    // Grouping: invoices + invoice_lines fall under "sales"; accounts +
    // companies fall under "core"; journal_entries under "gl".
    expect($result['row_counts_by_group'])->toMatchArray([
        'core' => 26,
        'sales' => 38,
        'gl' => 12,
    ]);

    expect($result['attachment_count'])->toBe(3)
        ->and($result['total_bytes'])->toBe(1024)
        ->and($result['app_version_mismatch'])->toBeFalse();

    expect($result['user_match_summary']['matched'])->toBe(1)
        ->and($result['user_match_summary']['fallback'])->toBe(1)
        ->and($result['user_match_summary']['samples'])->toHaveCount(2);

    expect($result['warnings'])->toBe([]);
});

it('reads a bundle re-zipped by macOS under a wrapping folder', function () {
    // macOS Finder's "Compress" nests every entry under a single folder and
    // adds __MACOSX/ + AppleDouble cruft. The inspector must look past that.
    $importer = User::factory()->create(['email' => 'importer@example.com']);

    $zipPath = $this->workDir.'/wrapped.zip';
    $wrap = 'lineledger-backup-acme/';
    buildFakeBundle($zipPath, [
        $wrap.'manifest.json' => json_encode(defaultManifest()),
        $wrap.'users.json' => json_encode([
            ['id' => 1, 'email' => 'someone@example.com', 'name' => 'Someone'],
        ]),
        $wrap.'data/companies.jsonl' => json_encode([
            'id' => 7, 'name' => 'Acme Inc.', 'slug' => 'acme-inc',
        ])."\n",
        // Cruft that must never be mistaken for the real manifest.
        '__MACOSX/'.$wrap.'._manifest.json' => 'not json',
        $wrap.'.DS_Store' => 'junk',
    ]);

    $result = (new BundleInspector(new UserRemapBuilder))->inspect($zipPath, $importer);

    expect($result['company'])->toBe([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
        'source_id' => 7,
    ]);
});

it('hard-refuses on schema version mismatch', function () {
    $importer = User::factory()->create();

    $zipPath = $this->workDir.'/badschema.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => json_encode(defaultManifest(['schema_version' => 999])),
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 1, 'name' => 'X', 'slug' => 'x'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);

    expect(fn () => $inspector->inspect($zipPath, $importer))
        ->toThrow(
            BundleValidationException::class,
            sprintf(
                'Bundle schema version 999 is incompatible with this instance (schema %d).',
                (int) config('version.schema'),
            ),
        );
});

it('throws when manifest.json is missing', function () {
    $importer = User::factory()->create();

    $zipPath = $this->workDir.'/no-manifest.zip';
    buildFakeBundle($zipPath, [
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 1, 'name' => 'X', 'slug' => 'x'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);

    expect(fn () => $inspector->inspect($zipPath, $importer))
        ->toThrow(BundleValidationException::class, 'manifest.json');
});

it('throws when manifest.json is malformed JSON', function () {
    $importer = User::factory()->create();

    $zipPath = $this->workDir.'/bad-manifest.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => '{not valid json',
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 1, 'name' => 'X', 'slug' => 'x'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);

    expect(fn () => $inspector->inspect($zipPath, $importer))
        ->toThrow(BundleValidationException::class, 'not valid JSON');
});

it('warns but does not throw when app version differs', function () {
    $importer = User::factory()->create();

    $zipPath = $this->workDir.'/appdrift.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => json_encode(defaultManifest(['app_version' => '0.9.0'])),
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 7, 'name' => 'Acme', 'slug' => 'acme'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);
    $result = $inspector->inspect($zipPath, $importer);

    expect($result['app_version_mismatch'])->toBeTrue()
        ->and($result['bundle_app_version'])->toBe('0.9.0');

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain('0.9.0');
});

it('warns when a manifest table is not in the local registry', function () {
    $importer = User::factory()->create();

    $manifest = defaultManifest();
    $manifest['tables']['legacy_widgets'] = ['rows' => 4, 'sha256' => 'z', 'bytes' => 20];

    $zipPath = $this->workDir.'/unknown-table.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => json_encode($manifest),
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 7, 'name' => 'Acme', 'slug' => 'acme'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);
    $result = $inspector->inspect($zipPath, $importer);

    $matched = array_filter(
        $result['warnings'],
        fn (string $w) => str_contains($w, "'legacy_widgets'"),
    );

    expect($matched)->not->toBeEmpty();

    // Unknown table still appears in the flat row_counts but not in the
    // grouped totals.
    expect($result['row_counts'])->toHaveKey('legacy_widgets')
        ->and($result['row_counts_by_group'])->not->toHaveKey('legacy_widgets');
});

it('warns when the manifest reports missing attachment files', function () {
    $importer = User::factory()->create();

    $zipPath = $this->workDir.'/missing-files.zip';
    buildFakeBundle($zipPath, [
        'manifest.json' => json_encode(defaultManifest([
            'files' => ['count' => 10, 'total_bytes' => 4096, 'missing_count' => 2],
        ])),
        'users.json' => json_encode([]),
        'data/companies.jsonl' => json_encode(['id' => 7, 'name' => 'Acme', 'slug' => 'acme'])."\n",
    ]);

    $inspector = new BundleInspector(new UserRemapBuilder);
    $result = $inspector->inspect($zipPath, $importer);

    $matched = array_filter(
        $result['warnings'],
        fn (string $w) => str_contains($w, 'missing at export time'),
    );

    expect($matched)->not->toBeEmpty();
});

it('throws BundleValidationException when the ZIP cannot be opened', function () {
    $importer = User::factory()->create();

    $inspector = new BundleInspector(new UserRemapBuilder);

    expect(fn () => $inspector->inspect($this->workDir.'/missing.zip', $importer))
        ->toThrow(BundleValidationException::class);
});
