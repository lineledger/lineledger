<?php

namespace App\Services\Backup;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Streams every row of a single Eloquent model to a JSON-Lines file
 * inside the backup working directory.
 *
 * One JSON object per line, cast-aware (uses `$model->toArray()`), with
 * incremental sha256 + byte accounting so the orchestrator can publish
 * per-table integrity in the manifest.
 *
 * The writer is **stateless** — every call accepts the company id
 * explicitly and applies an explicit `where('company_id', $companyId)`
 * filter (or `where('id', $companyId)` for the `companies` table) under
 * `withoutGlobalScopes()`. That sidesteps the `BelongsToCompany` global
 * scope so the orchestrator does not need to bind `current_company`,
 * keeping the writer safe to call from queues and unit tests.
 *
 * Child tables that have no direct `company_id` column (e.g.
 * `journal_lines`, `receipt_applications`, `bill_payment_applications`)
 * must be filtered via the caller-supplied `$scope` closure, which is
 * applied to the query builder in place of the default company filter.
 */
final class JsonlTableWriter
{
    /**
     * Stream every row of `$modelClass` for `$companyId` to
     * `{workDir}/data/{tableName}.jsonl`.
     *
     * @param  class-string<Model>  $modelClass
     * @param  int  $companyId  Tenant id used for the explicit company filter.
     * @param  string  $workDir  Absolute path to the backup working dir.
     * @param  string  $tableName  Physical table name (used for filename + schema introspection).
     * @param  Closure|null  $rowTransform  Optional `fn(array $row, Model $model): array` — applied
     *                                      after `$model->toArray()`; the returned array is written.
     *                                      Used by the orchestrator to strip Stripe columns from
     *                                      `companies` and rewrite `attachments.path`.
     * @param  Closure|null  $scope  Optional `fn(Builder $q): void` — applied to the query in place
     *                               of the default company filter. Required when `$tableName` has
     *                               no `company_id` column.
     * @return array{rows: int, sha256: string, bytes: int}
     */
    public function write(
        string $modelClass,
        int $companyId,
        string $workDir,
        string $tableName,
        ?Closure $rowTransform = null,
        ?Closure $scope = null,
    ): array {
        $dataDir = $workDir.'/data';

        if (! is_dir($dataDir) && ! @mkdir($dataDir, 0755, true) && ! is_dir($dataDir)) {
            throw new RuntimeException("Unable to create backup data directory: {$dataDir}");
        }

        $filePath = $dataDir.'/'.$tableName.'.jsonl';
        $handle = fopen($filePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open JSONL file for write: {$filePath}");
        }

        $hashCtx = hash_init('sha256');
        $rowCount = 0;
        $byteCount = 0;

        try {
            /** @var Builder $query */
            $query = $modelClass::query()->withoutGlobalScopes();

            if ($scope !== null) {
                $scope($query);
            } elseif ($tableName === 'companies') {
                $query->where('id', $companyId);
            } elseif (Schema::hasColumn($tableName, 'company_id')) {
                $query->where('company_id', $companyId);
            } else {
                throw new RuntimeException(
                    "Table [{$tableName}] has no company_id column and no \$scope closure was provided."
                );
            }

            $query->chunkById(5000, function ($chunk) use (
                $handle,
                &$hashCtx,
                &$rowCount,
                &$byteCount,
                $rowTransform,
            ): void {
                foreach ($chunk as $model) {
                    /** @var Model $model */
                    $row = $model->toArray();

                    if ($rowTransform !== null) {
                        $row = $rowTransform($row, $model);
                    }

                    $line = json_encode(
                        $row,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );

                    if ($line === false) {
                        throw new RuntimeException(
                            'json_encode failed for row in '.$model::class.': '.json_last_error_msg()
                        );
                    }

                    $line .= "\n";

                    hash_update($hashCtx, $line);
                    fwrite($handle, $line);

                    $rowCount++;
                    $byteCount += strlen($line);
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'rows' => $rowCount,
            'sha256' => hash_final($hashCtx),
            'bytes' => $byteCount,
        ];
    }
}
