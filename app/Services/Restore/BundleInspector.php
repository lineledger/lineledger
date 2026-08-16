<?php

namespace App\Services\Restore;

use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Restore\Exceptions\BundleValidationException;
use JsonException;
use ZipArchive;

/**
 * Reads a Phase 1 backup ZIP and produces the preview struct the restore UI
 * renders before the user confirms the import.
 *
 * Pure / read-only — does no DB writes. Throws `BundleValidationException`
 * for any condition that should outright reject the upload (unopenable ZIP,
 * missing/malformed manifest, schema-version mismatch). Soft conditions
 * (app-version drift, unknown tables, missing attachment files reported in
 * the manifest) surface as `warnings` so the UI can show a yellow banner
 * without blocking the restore.
 */
final class BundleInspector
{
    public function __construct(
        private readonly UserRemapBuilder $userRemap,
    ) {}

    /**
     * Open the ZIP at `$zipPath`, validate the manifest, and return a
     * preview struct for the UI + orchestrator.
     *
     * @return array{
     *     manifest: array<string, mixed>,
     *     company: array{name:string, slug:string, source_id:int},
     *     row_counts: array<string, int>,
     *     row_counts_by_group: array<string, int>,
     *     attachment_count: int,
     *     total_bytes: int,
     *     app_version_mismatch: bool,
     *     bundle_app_version: string,
     *     target_app_version: string,
     *     user_match_summary: array{matched:int, fallback:int, samples:array<int, array<string,mixed>>},
     *     warnings: list<string>,
     * }
     *
     * @throws BundleValidationException When the bundle is unreadable or rejected.
     */
    public function inspect(string $zipPath, User $importingUser): array
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath);

        if ($openResult !== true) {
            throw new BundleValidationException(
                "Unable to open backup ZIP at {$zipPath} (ZipArchive error code: {$openResult})."
            );
        }

        try {
            // Tolerate a bundle that was extracted then re-zipped (e.g. by macOS
            // Finder, which nests everything under a wrapping folder + __MACOSX).
            $prefix = BundleLayout::rootPrefix($this->entryNames($zip));

            $manifest = $this->readManifest($zip, $prefix);

            $this->assertSchemaVersion($manifest);

            $warnings = [];

            // App version check — warn, do not refuse.
            $targetAppVersion = (string) config('version.app');
            $bundleAppVersion = (string) ($manifest['app_version'] ?? '');
            $appVersionMismatch = $bundleAppVersion !== $targetAppVersion;

            if ($appVersionMismatch) {
                $warnings[] = sprintf(
                    'Bundle was created by app version %s; this instance is on version %s. Restore will proceed but be aware of behavioral differences.',
                    $bundleAppVersion !== '' ? $bundleAppVersion : '(unknown)',
                    $targetAppVersion,
                );
            }

            // Cross-reference manifest tables against the registry. Unknown
            // tables (bundle has something we don't recognize) are a warning;
            // registry tables missing from the manifest are fine (just empty
            // in the source).
            $registryTables = $this->registryTablesByName();
            $manifestTables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

            $rowCounts = [];
            $rowCountsByGroup = [];

            foreach ($manifestTables as $tableName => $tableMeta) {
                $rows = is_array($tableMeta) ? (int) ($tableMeta['rows'] ?? 0) : 0;
                $rowCounts[$tableName] = $rows;

                if (! isset($registryTables[$tableName])) {
                    $warnings[] = sprintf(
                        "Bundle contains data for table '%s' which this instance does not recognize. It will be skipped.",
                        $tableName,
                    );

                    continue;
                }

                $group = $registryTables[$tableName]['group'];
                $rowCountsByGroup[$group] = ($rowCountsByGroup[$group] ?? 0) + $rows;
            }

            // Users + remap preview.
            $bundleUsers = $this->readUsers($zip, $prefix);
            $remap = $this->userRemap->build($bundleUsers, $importingUser);

            $matched = 0;
            $fallback = 0;
            foreach ($remap['matches'] as $entry) {
                if ($entry['match'] === 'email') {
                    $matched++;
                } else {
                    $fallback++;
                }
            }

            $samples = array_slice($remap['matches'], 0, 5);

            // Company preview from data/companies.jsonl[0].
            $company = $this->readCompanyPreview($zip, $prefix);

            // Attachment file counts come straight from the manifest.
            $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
            $attachmentCount = (int) ($files['count'] ?? 0);
            $totalBytes = (int) ($files['total_bytes'] ?? 0);
            $missingCount = (int) ($files['missing_count'] ?? 0);

            if ($missingCount > 0) {
                $warnings[] = sprintf(
                    '%d attachment %s were missing at export time and won\'t be restorable.',
                    $missingCount,
                    $missingCount === 1 ? 'file' : 'files',
                );
            }

            return [
                'manifest' => $manifest,
                'company' => $company,
                'row_counts' => $rowCounts,
                'row_counts_by_group' => $rowCountsByGroup,
                'attachment_count' => $attachmentCount,
                'total_bytes' => $totalBytes,
                'app_version_mismatch' => $appVersionMismatch,
                'bundle_app_version' => $bundleAppVersion,
                'target_app_version' => $targetAppVersion,
                'user_match_summary' => [
                    'matched' => $matched,
                    'fallback' => $fallback,
                    'samples' => $samples,
                ],
                'warnings' => $warnings,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Every archive-relative entry name in the ZIP, for root-prefix detection.
     *
     * @return list<string>
     */
    private function entryNames(ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name !== false) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(ZipArchive $zip, string $prefix): array
    {
        $raw = $zip->getFromName($prefix.'manifest.json');

        if ($raw === false) {
            throw new BundleValidationException(
                'Bundle is missing manifest.json — this does not appear to be a LineLedger backup.'
            );
        }

        try {
            $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BundleValidationException(
                'Bundle manifest.json is not valid JSON: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($manifest)) {
            throw new BundleValidationException('Bundle manifest.json did not decode to an object.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertSchemaVersion(array $manifest): void
    {
        $targetSchema = (int) config('version.schema');
        $bundleSchema = $manifest['schema_version'] ?? null;

        if ($bundleSchema === null || ! is_int($bundleSchema)) {
            throw new BundleValidationException(
                'Bundle manifest is missing schema_version; cannot verify compatibility.'
            );
        }

        if ($bundleSchema !== $targetSchema) {
            throw new BundleValidationException(sprintf(
                'Bundle schema version %d is incompatible with this instance (schema %d).',
                $bundleSchema,
                $targetSchema,
            ));
        }
    }

    /**
     * @return array<int, array{id:int,email:string,name:string}>
     */
    private function readUsers(ZipArchive $zip, string $prefix): array
    {
        $raw = $zip->getFromName($prefix.'users.json');

        if ($raw === false) {
            // Empty users.json is unusual but not fatal — degrade gracefully.
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BundleValidationException(
                'Bundle users.json is not valid JSON: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<int, array{id:int,email:string,name:string}> $decoded */
        return $decoded;
    }

    /**
     * @return array{name:string, slug:string, source_id:int}
     */
    private function readCompanyPreview(ZipArchive $zip, string $prefix): array
    {
        $raw = $zip->getFromName($prefix.'data/companies.jsonl');

        if ($raw === false) {
            throw new BundleValidationException(
                'Bundle is missing data/companies.jsonl — cannot read source company metadata.'
            );
        }

        $firstLine = strtok($raw, "\n");

        if ($firstLine === false || trim((string) $firstLine) === '') {
            throw new BundleValidationException(
                'Bundle data/companies.jsonl is empty — no source company row to restore.'
            );
        }

        try {
            $row = json_decode(trim((string) $firstLine), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BundleValidationException(
                'Bundle data/companies.jsonl first line is not valid JSON: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($row)) {
            throw new BundleValidationException(
                'Bundle data/companies.jsonl first line did not decode to an object.'
            );
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'source_id' => (int) ($row['id'] ?? 0),
        ];
    }

    /**
     * Index `BackupTableRegistry::tables()` by table name for O(1) lookups.
     *
     * @return array<string, array{table:string, model:class-string, group:string}>
     */
    private function registryTablesByName(): array
    {
        $byName = [];

        foreach (BackupTableRegistry::tables() as $entry) {
            $byName[$entry['table']] = $entry;
        }

        return $byName;
    }
}
