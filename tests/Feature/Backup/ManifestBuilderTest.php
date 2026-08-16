<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\ManifestBuilder;

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'user@example.test',
    ]);
    $this->company = Company::factory()->create([
        'name' => 'Acme Inc.',
    ]);

    $this->workDir = sys_get_temp_dir().'/backup-manifest-test-'.uniqid();
    mkdir($this->workDir, 0755, true);

    $this->context = [
        'company' => $this->company,
        'exportedBy' => $this->user,
        'tables' => [
            'accounts' => ['rows' => 142, 'sha256' => str_repeat('a', 64), 'bytes' => 5120],
            'journal_lines' => ['rows' => 18420, 'sha256' => str_repeat('b', 64), 'bytes' => 1_048_576],
        ],
        'files' => [
            'count' => 312,
            'total_bytes' => 48201234,
            'missing' => [101, 202],
        ],
        'users' => [
            'count' => 4,
            'sha256' => str_repeat('c', 64),
        ],
        'attachments' => [
            'count' => 312,
            'sha256_index' => str_repeat('d', 64),
        ],
    ];
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

it('builds a manifest matching the documented shape', function () {
    $builder = new ManifestBuilder;
    $manifest = $builder->build($this->context);

    expect($manifest)->toHaveKeys([
        'schema_version', 'app_version', 'exported_at', 'exported_by',
        'company', 'tables', 'files', 'users', 'exclusions', 'import_hints',
    ]);

    expect($manifest['schema_version'])->toBe((int) config('version.schema'))
        ->and($manifest['app_version'])->toBe((string) config('version.app'));

    expect($manifest['exported_by'])->toBe([
        'user_id' => $this->user->id,
        'email' => 'user@example.test',
    ]);

    expect($manifest['company'])->toBe([
        'id' => $this->company->id,
        'name' => 'Acme Inc.',
        'slug' => $this->company->slug,
    ]);

    expect($manifest['tables'])->toBe($this->context['tables']);

    expect($manifest['files'])->toBe([
        'count' => 312,
        'total_bytes' => 48201234,
        'missing_count' => 2,
    ]);

    expect($manifest['users'])->toBe([
        'count' => 4,
        'sha256' => str_repeat('c', 64),
    ]);

    expect($manifest['exclusions'])->toBe(BackupTableRegistry::excludedTables());

    expect($manifest['import_hints'])->toBe([
        'remap_user_ids_by_email' => true,
        'disable_accounting_audit_log_immutability_during_import' => true,
    ]);

    // exported_at must be ISO-8601 with a Z suffix (UTC).
    expect($manifest['exported_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('writes manifest.json and returns its sha256 + bytes', function () {
    $builder = new ManifestBuilder;
    $result = $builder->write($this->context, $this->workDir);

    $path = $this->workDir.'/manifest.json';
    expect(file_exists($path))->toBeTrue();

    $raw = file_get_contents($path);
    expect($result['sha256'])->toBe(hash('sha256', $raw))
        ->and($result['bytes'])->toBe(strlen($raw));

    // Round-trip: the file must parse as the same array we built.
    $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['company']['id'])->toBe($this->company->id)
        ->and($decoded['tables']['accounts']['rows'])->toBe(142);
});

it('handles a missing[] array of files cleanly', function () {
    $ctx = $this->context;
    unset($ctx['files']['missing']);

    $builder = new ManifestBuilder;
    $manifest = $builder->build($ctx);

    expect($manifest['files']['missing_count'])->toBe(0);
});
