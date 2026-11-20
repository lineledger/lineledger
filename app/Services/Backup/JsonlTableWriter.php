<?php

namespace App\Services\Backup;

use App\Enums\EncryptedValueState;
use App\Support\Encryption\EncryptedColumns;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Streams every row of a single Eloquent model to a JSON-Lines file
 * inside the backup working directory.
 *
 * One JSON object per line, cast-aware (uses `$model->toArray()`), with
 * incremental sha256 + byte accounting so the orchestrator can publish
 * per-table integrity in the manifest.
 *
 * Columns with an `encrypted` cast are written as their plaintext
 * ({@see self::ENCRYPTED_COLUMN_ENCODING}), and the restore encrypts them again
 * with the target instance's key. A bundle therefore never needs the APP_KEY of
 * the instance that made it, and the restored column holds ciphertext the
 * target's cast can read.
 *
 * The writer is **stateless** — every call accepts the company id
 * explicitly and applies an explicit `where('company_id', $companyId)`
 * filter (or `where('id', $companyId)` for the `companies` table) under
 * `withoutGlobalScopes()`. That sidesteps the `BelongsToCompany` global
 * scope so the orchestrator does not need to bind `current_company`,
 * keeping the writer safe to call from queues and unit tests.
 *
 * Which rows belong to the organization comes from {@see BackupScopes}
 * (child tables without a `company_id` filter through their parent), unless the
 * caller passes its own `$scope` closure.
 *
 * Columns a model hides from serialisation (`$hidden`) are only written when
 * {@see self::HIDDEN_COLUMNS} says so: a hidden column is usually a secret, so
 * leaving one out is the safe default, and the arch test makes every hidden
 * column on a backed-up model an explicit decision.
 */
final class JsonlTableWriter
{
    /**
     * How a bundle carries a column with an `encrypted` cast, recorded per column
     * in the manifest (`tables.{table}.encrypted_columns.{column}.encoding`).
     *
     * `plaintext` is what bundles have always held, since `toArray()` decrypts
     * through the cast; the manifest now says so. The restore refuses any other
     * encoding, so a later format (a bundle sealed with its own key) is never
     * restored by this version as if its ciphertext were the value.
     */
    public const ENCRYPTED_COLUMN_ENCODING = 'plaintext';

    /**
     * Every column a backed-up model hides from serialisation, and whether the
     * bundle carries it anyway (`true`) or leaves it out (`false`).
     *
     *  - `contacts.portal_password`: the employee portal password, already a
     *    one-way hash. Without it every restored employee is locked out of the
     *    portal until someone resets their password.
     *  - `company_api_keys.token_hash`: the key's secret. A restored key comes
     *    back revoked with a hash nobody holds ({@see RowTransformer}), so the
     *    original hash would only be a liability in the bundle.
     *
     * @var array<string, array<string, bool>>
     */
    public const HIDDEN_COLUMNS = [
        'contacts' => ['portal_password' => true],
        'company_api_keys' => ['token_hash' => false],
    ];

    /**
     * Per model class: the hidden columns to make visible, and the encrypted
     * columns to write as plaintext. Worked out once per class.
     *
     * @var array<class-string<Model>, array{visible: list<string>, encrypted: list<string>}>
     */
    private array $columnPlans = [];

    /**
     * Stream every row of `$modelClass` for `$companyId` to
     * `{workDir}/data/{tableName}.jsonl`.
     *
     * For a model with encrypted columns the result also has `encrypted_columns`:
     * per column, its encoding and how many values could not be decrypted (and
     * were written as null). The orchestrator puts the result in the manifest.
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
     * @return array{rows: int, sha256: string, bytes: int, encrypted_columns?: array<string, array{encoding: string, unreadable: int}>}
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

        $encryptedColumns = $this->columnPlan($modelClass, $tableName)['encrypted'];
        $unreadable = array_fill_keys($encryptedColumns, 0);

        try {
            /** @var Builder $query */
            $query = $modelClass::query()->withoutGlobalScopes();

            if ($scope !== null) {
                $scope($query);
            } else {
                BackupScopes::apply($query, $tableName, $companyId);
            }

            $query->chunkById(5000, function ($chunk) use (
                $handle,
                &$hashCtx,
                &$rowCount,
                &$byteCount,
                &$unreadable,
                $rowTransform,
                $tableName,
            ): void {
                foreach ($chunk as $model) {
                    /** @var Model $model */
                    $row = $this->serializeRow($model, $tableName, $unreadable);

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

        $result = [
            'rows' => $rowCount,
            'sha256' => hash_final($hashCtx),
            'bytes' => $byteCount,
        ];

        if ($encryptedColumns !== []) {
            $result['encrypted_columns'] = array_map(
                static fn (int $count): array => ['encoding' => self::ENCRYPTED_COLUMN_ENCODING, 'unreadable' => $count],
                $unreadable,
            );
        }

        return $result;
    }

    /**
     * The row as the bundle carries it: what `toArray()` serialises, plus the
     * hidden columns {@see self::HIDDEN_COLUMNS} exports, except for the
     * encrypted columns. `toArray()` would decrypt those through the cast too,
     * but the cast throws on a value that no longer decrypts, and one such SIN
     * would fail the whole backup. So they are hidden from `toArray()` and read
     * from the raw attribute instead: ciphertext is decrypted, a value that does
     * not decrypt is written as null and counted, and a value an older restore
     * stored unencrypted is written as it is (it is already the plaintext).
     *
     * Public so the backup verifier serialises live rows exactly as a bundle
     * holds them.
     *
     * @param  array<string, int>  $unreadable  Per-column count, incremented in place.
     * @return array<string, mixed>
     */
    public function serializeRow(Model $model, string $tableName, array &$unreadable = []): array
    {
        $plan = $this->columnPlan($model::class, $tableName);

        if ($plan['visible'] !== []) {
            $model->makeVisible($plan['visible']);
        }

        if ($plan['encrypted'] === []) {
            return $model->toArray();
        }

        $raw = $model->getAttributes();
        $row = $model->makeHidden($plan['encrypted'])->toArray();

        foreach ($plan['encrypted'] as $column) {
            if (! array_key_exists($column, $raw)) {
                continue;
            }

            $value = EncryptedColumns::read($model::class, $raw[$column]);

            if ($value['state'] === EncryptedValueState::Unreadable) {
                $unreadable[$column] = ($unreadable[$column] ?? 0) + 1;
            }

            $row[$column] = $value['plaintext'];
        }

        return $row;
    }

    /**
     * The hidden columns this table exports, and its encrypted columns that end
     * up in the bundle. An encrypted column the model hides stays out unless
     * {@see self::HIDDEN_COLUMNS} exports it.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{visible: list<string>, encrypted: list<string>}
     */
    private function columnPlan(string $modelClass, string $tableName): array
    {
        if (isset($this->columnPlans[$modelClass])) {
            return $this->columnPlans[$modelClass];
        }

        $model = new $modelClass;
        $exportedHidden = array_keys(array_filter(self::HIDDEN_COLUMNS[$tableName] ?? []));
        $hidden = array_values(array_diff($model->getHidden(), $exportedHidden));
        $visible = $model->getVisible();

        $encrypted = array_values(array_filter(
            EncryptedColumns::of($modelClass),
            static fn (string $column): bool => ! in_array($column, $hidden, true)
                && ($visible === [] || in_array($column, $visible, true)),
        ));

        return $this->columnPlans[$modelClass] = [
            'visible' => array_values(array_intersect($exportedHidden, $model->getHidden())),
            'encrypted' => $encrypted,
        ];
    }
}
