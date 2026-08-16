<?php

namespace App\Services\Backup;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Composes the bundle's `manifest.json` from the row-count + integrity
 * data gathered by the orchestrator.
 *
 * Stateless. The Phase 2 importer reads this file before doing anything
 * else: `schema_version` must match exactly; `app_version` is checked
 * with a warning on mismatch; per-table `sha256` is re-verified.
 */
final class ManifestBuilder
{
    /**
     * Build the manifest array from a context hash supplied by the
     * orchestrator. Pure — does no I/O.
     *
     * @param  array{
     *     company: Company,
     *     exportedBy: User,
     *     tables: array<string, array{rows: int, sha256: string, bytes: int}>,
     *     files: array{count: int, total_bytes: int, missing?: array<int, mixed>},
     *     users: array{count: int, sha256: string},
     *     attachments?: array{count?: int, sha256_index?: string}
     * }  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $company = $context['company'];
        $user = $context['exportedBy'];
        $files = $context['files'];
        $users = $context['users'];

        $missingCount = isset($files['missing']) ? count($files['missing']) : 0;

        return [
            'schema_version' => (int) config('version.schema'),
            'app_version' => (string) config('version.app'),
            'exported_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'exported_by' => [
                'user_id' => (int) $user->id,
                'email' => $user->email,
            ],
            'company' => [
                'id' => (int) $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
            'tables' => $context['tables'],
            'files' => [
                'count' => (int) $files['count'],
                'total_bytes' => (int) $files['total_bytes'],
                'missing_count' => $missingCount,
            ],
            'users' => [
                'count' => (int) $users['count'],
                'sha256' => (string) $users['sha256'],
            ],
            'exclusions' => BackupTableRegistry::excludedTables(),
            'import_hints' => [
                'remap_user_ids_by_email' => true,
                'disable_accounting_audit_log_immutability_during_import' => true,
            ],
        ];
    }

    /**
     * Build the manifest from `$context` and write it as pretty-printed JSON
     * to `{workDir}/manifest.json`. Returns the file's sha256 + size.
     *
     * @param  array<string, mixed>  $context
     * @return array{sha256: string, bytes: int}
     */
    public function write(array $context, string $workDir): array
    {
        $manifest = $this->build($context);

        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('json_encode failed for manifest.json: '.json_last_error_msg());
        }

        if (! is_dir($workDir) && ! @mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Unable to create backup working directory: {$workDir}");
        }

        $filePath = $workDir.'/manifest.json';

        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write manifest.json to {$filePath}");
        }

        return [
            'sha256' => hash('sha256', $json),
            'bytes' => strlen($json),
        ];
    }
}
