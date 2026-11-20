<?php

namespace App\Services\Backup\Verification;

use App\Services\Backup\BackupScopes;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\JsonlTableWriter;
use App\Services\Restore\RestoreTrace;
use App\Services\Restore\RowTransformer;
use App\Support\Gifi\GifiCodeCorrections;
use Illuminate\Database\Eloquent\Model;

/**
 * Compares a backup bundle, row by row and column by column, against an
 * organization in the database:
 *
 *  - **restore** mode: the copy a restore made, paired with the bundle through
 *    the restore's {@see RestoreTrace}. Every reference must name the copy's
 *    own row for the one the bundle named, judged against the final id map
 *    (so a reference set before its row was restored can't hide), and every
 *    other column must be unchanged apart from {@see RestoreExpectations}.
 *  - **export** mode: the organization the bundle was made from, ids unchanged.
 *    Every database column must be in the bundle, apart from what the exporter
 *    leaves out on purpose, so a column a serialiser drops can't go unnoticed.
 *
 * Both sides are serialised the way the exporter writes a bundle row
 * ({@see JsonlTableWriter::serializeRow()}), so casts, enums and encryption
 * compare like for like on MySQL and SQLite.
 */
final class RowComparator
{
    /**
     * Tables a restore adds rows to that the bundle doesn't have: the
     * importer's own Owner membership, and predefined roles a bundle lacked.
     *
     * @var list<string>
     */
    public const EXTRA_ROWS_ALLOWED = ['company_members', 'company_member_roles', 'roles', 'role_permissions'];

    private const CHUNK = 500;

    public function __construct(private readonly JsonlTableWriter $serializer) {}

    public function compareRestore(BundleReader $bundle, RestoreTrace $trace, VerificationReport $report): void
    {
        $transformer = new RowTransformer(
            idMapper: $trace->ids,
            userIdMap: $trace->userIdMap,
            importingUserId: $trace->importingUserId,
            newCompanyId: $trace->companyId,
            fallbackUserIds: $trace->fallbackUserIds,
        );
        $correctGifi = GifiCodeCorrections::bundleNeedsCorrection($bundle->manifest());
        $skipped = array_map(static fn (array $ids): array => array_flip($ids), $trace->skipped);

        foreach (BackupTableRegistry::tables() as $entry) {
            $table = $entry['table'];

            $this->compareTable(
                $bundle,
                $entry['model'],
                $table,
                $trace->companyId,
                $report,
                pair: function (array $source) use ($table, $trace, $skipped): int|string|null {
                    if ($table === 'companies') {
                        return $trace->companyId;
                    }

                    $oldId = isset($source['id']) ? (int) $source['id'] : null;

                    if ($oldId !== null && isset($skipped[$table][$oldId])) {
                        return 'skipped';
                    }

                    return $oldId === null ? null : $trace->ids->get($table, $oldId);
                },
                expected: fn (array $source): array => $this->expectedRestoredRow($table, $source, $trace, $transformer, $correctGifi),
                rules: RestoreExpectations::RESTORE[$table] ?? [],
                extrasAllowed: in_array($table, self::EXTRA_ROWS_ALLOWED, true),
                checkColumnCoverage: false,
            );
        }
    }

    public function compareExport(BundleReader $bundle, int $companyId, VerificationReport $report): void
    {
        foreach (BackupTableRegistry::tables() as $entry) {
            $table = $entry['table'];

            $this->compareTable(
                $bundle,
                $entry['model'],
                $table,
                $companyId,
                $report,
                pair: static fn (array $source): ?int => isset($source['id']) ? (int) $source['id'] : null,
                expected: static fn (array $source): array => $source,
                rules: RestoreExpectations::EXPORT[$table] ?? [],
                extrasAllowed: false,
                checkColumnCoverage: true,
            );
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  callable(array<string, mixed>): (int|string|null)  $pair  The database id a bundle row should have; 'skipped' for a row left out on purpose.
     * @param  callable(array<string, mixed>): array<string, mixed>  $expected  The row as it should read back.
     * @param  array<string, string>  $rules
     */
    private function compareTable(
        BundleReader $bundle,
        string $modelClass,
        string $table,
        int $companyId,
        VerificationReport $report,
        callable $pair,
        callable $expected,
        array $rules,
        bool $extrasAllowed,
        bool $checkColumnCoverage,
    ): void {
        $result = new TableComparison;

        $matched = 0;
        $chunk = [];

        foreach ($bundle->rows($table) as $source) {
            $result->bundleRows++;
            $id = $pair($source);

            if ($id === 'skipped') {
                $result->skipped++;

                continue;
            }

            if ($id === null) {
                $result->missing++;
                $result->sample(sprintf('#%s: not restored', $source['id'] ?? '?'));

                continue;
            }

            $chunk[$id] = $source;

            if (count($chunk) >= self::CHUNK) {
                $matched += $this->compareChunk($chunk, $result, $modelClass, $table, $companyId, $expected, $rules, $checkColumnCoverage);
                $chunk = [];
            }
        }

        $matched += $this->compareChunk($chunk, $result, $modelClass, $table, $companyId, $expected, $rules, $checkColumnCoverage);

        $result->restoredRows = BackupScopes::query($modelClass, $table, $companyId)->count();
        $extra = $result->restoredRows - $matched;

        if ($extra > 0 && ! $extrasAllowed) {
            $result->extra = $extra;
            $result->sample(sprintf('%d row(s) the backup does not have', $extra));
        }

        $report->table($table, $result->toArray());
    }

    /**
     * Compare one chunk of bundle rows (keyed by the database id each should
     * have) with the rows the database holds. Returns how many were found.
     *
     * @param  array<int|string, array<string, mixed>>  $chunk
     * @param  class-string<Model>  $modelClass
     * @param  callable(array<string, mixed>): array<string, mixed>  $expected
     * @param  array<string, string>  $rules
     */
    private function compareChunk(
        array $chunk,
        TableComparison $result,
        string $modelClass,
        string $table,
        int $companyId,
        callable $expected,
        array $rules,
        bool $checkColumnCoverage,
    ): int {
        if ($chunk === []) {
            return 0;
        }

        $rows = BackupScopes::query($modelClass, $table, $companyId)
            ->whereIn('id', array_keys($chunk))
            ->get()
            ->keyBy('id');

        $matched = 0;

        foreach ($chunk as $id => $source) {
            $model = $rows->get($id);

            if ($model === null) {
                $result->missing++;
                $result->sample(sprintf('#%s: not restored', $source['id'] ?? '?'));

                continue;
            }

            $matched++;
            $differences = $this->differences($expected($source), $this->serialize($model, $table), $rules);

            if ($checkColumnCoverage) {
                foreach (array_keys($model->getAttributes()) as $column) {
                    if (! array_key_exists($column, $source) && ! RestoreExpectations::notExported($table, $column)) {
                        $differences[] = "{$column} is not in the backup";
                    }
                }
            }

            if ($differences !== []) {
                $result->mismatched++;
                $result->sample(sprintf('#%s: %s', $source['id'] ?? '?', implode('; ', array_slice($differences, 0, 3))));
            }
        }

        return $matched;
    }

    /**
     * The row a restore should have made of `$source`.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function expectedRestoredRow(string $table, array $source, RestoreTrace $trace, RowTransformer $transformer, bool $correctGifi): array
    {
        $expected = $source;
        unset($expected['id']);

        foreach ($source as $column => $value) {
            $column = (string) $column;

            if ($column === 'company_id') {
                $expected[$column] = $trace->companyId;

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (RowTransformer::isUserColumn($column)) {
                $expected[$column] = $trace->userIdMap[(int) $value] ?? $trace->importingUserId;

                continue;
            }

            $target = RowTransformer::referenceTarget($table, $column);

            if ($target !== null) {
                $expected[$column] = $this->mapped($trace, $table, $column, $target, $value);
            }
        }

        foreach (RowTransformer::POLYMORPHIC_PAIRS[$table] ?? [] as $columns) {
            $type = $source[$columns['type']] ?? null;
            $id = $source[$columns['id']] ?? null;

            if ($id === null) {
                continue;
            }

            $target = is_string($type) ? RowTransformer::morphTable($type) : null;

            $expected[$columns['id']] = $target === null
                ? $this->unmapped($table, $columns['id'])
                : $this->mapped($trace, $table, $columns['id'], $target, $id);
        }

        // References the transformer resolves in a per-table quirk.
        switch ($table) {
            case 'company_member_roles':
                $expected['company_member_id'] = $trace->ids->get('company_members', (int) $source['company_member_id']);
                break;

            case 'document_folders':
                if (($source['created_by_member_id'] ?? null) !== null) {
                    $expected['created_by_member_id'] = $trace->ids->get('company_members', (int) $source['created_by_member_id']);
                }

                if (is_array($source['viewer_member_ids'] ?? null)) {
                    $viewers = array_values(array_filter(array_map(
                        static fn (mixed $id): ?int => $trace->ids->get('company_members', (int) $id),
                        $source['viewer_member_ids'],
                    )));
                    $expected['viewer_member_ids'] = $viewers === [] ? null : $viewers;
                }
                break;

            case 'inbox_items':
                $documentTable = RowTransformer::INBOX_PROMOTED_DOCUMENT_TABLES[(string) ($source['promoted_document_type'] ?? '')] ?? null;

                if ($documentTable !== null && ($source['promoted_document_id'] ?? null) !== null) {
                    $expected['promoted_document_id'] = $trace->ids->get($documentTable, (int) $source['promoted_document_id']);
                }
                break;

            case 'accounts':
                if ($correctGifi && isset($source['gifi_code'])) {
                    $expected['gifi_code'] = GifiCodeCorrections::correct((string) $source['gifi_code']);
                }
                break;
        }

        return array_replace($expected, $transformer->remapJsonIds($table, $source));
    }

    /**
     * The copy's id for a reference, by the final id map. A reference to a
     * row the bundle doesn't hold restores as null (or as its
     * {@see RowTransformer::DANGLING_REFERENCE_POLICY} says).
     */
    private function mapped(RestoreTrace $trace, string $table, string $column, string $target, mixed $value): ?int
    {
        return $trace->ids->get($target, (int) $value) ?? $this->unmapped($table, $column);
    }

    private function unmapped(string $table, string $column): ?int
    {
        return (RowTransformer::DANGLING_REFERENCE_POLICY[$table][$column] ?? null) === 'zero' ? 0 : null;
    }

    /**
     * Column-by-column differences between the expected and actual rows. Only
     * the expected row's columns are compared: a column the bundle predates
     * restores as its default.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @param  array<string, string>  $rules
     * @return list<string>
     */
    private function differences(array $expected, array $actual, array $rules): array
    {
        $differences = [];

        foreach ($expected as $column => $value) {
            if ($column === 'id') {
                continue;
            }

            $have = $actual[$column] ?? null;
            $rule = $rules[$column] ?? null;

            $ok = match ($rule) {
                RestoreExpectations::IGNORE => true,
                RestoreExpectations::NULL => $have === null,
                RestoreExpectations::FALSE => $have === false || $have === 0 || $have === '0',
                RestoreExpectations::FILLED => $value === null ? $have !== null : self::same($value, $have),
                RestoreExpectations::PENDING_EXPIRES => self::same($value === 'pending' ? 'expired' : $value, $have),
                default => self::same($value, $have),
            };

            if (! $ok) {
                $differences[] = sprintf('%s: expected %s, got %s', $column, self::show($value, $rule), self::show($have));
            }
        }

        return $differences;
    }

    /**
     * The row as a bundle would hold it: serialised by the exporter, then
     * through JSON, exactly as the bundle's side was.
     *
     * @return array<string, mixed>
     */
    private function serialize(Model $model, string $table): array
    {
        $row = $this->serializer->serializeRow($model, $table);

        return json_decode((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true) ?? [];
    }

    /**
     * Equal for the purposes of a backup: datetimes to the second (a restore
     * keeps whole seconds), numbers by value, JSON by content.
     */
    public static function same(mixed $expected, mixed $actual): bool
    {
        return self::normalize($expected) === self::normalize($actual);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return self::number((string) $value);
        }

        if (is_string($value)) {
            if (strlen($value) >= 19 && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value) === 1) {
                return substr(str_replace('T', ' ', $value), 0, 19);
            }

            if (is_numeric($value)) {
                return self::number($value);
            }
        }

        return $value;
    }

    private static function number(string $value): string
    {
        if (! str_contains($value, '.') && ! str_contains(strtolower($value), 'e')) {
            return ltrim($value, '+') === '-0' ? '0' : $value;
        }

        $normalized = rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.');

        return $normalized === '-0' ? '0' : $normalized;
    }

    private static function show(mixed $value, ?string $rule = null): string
    {
        if ($rule === RestoreExpectations::NULL) {
            return 'null';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return mb_strimwidth($encoded === false ? '?' : $encoded, 0, 80, '…');
    }
}
