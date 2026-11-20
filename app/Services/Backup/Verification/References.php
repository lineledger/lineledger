<?php

namespace App\Services\Backup\Verification;

use App\Services\Restore\JsonIdPaths;
use App\Services\Restore\RowTransformer;

/**
 * The row references a bundle row holds, per {@see RowTransformer}'s maps:
 * parent foreign keys, polymorphic pairs and ids inside JSON columns. User
 * columns are left out (they resolve through users.json, by email).
 */
final class References
{
    /**
     * @param  array<string, mixed>  $row
     * @return list<array{column: string, table: string, id: int}>
     */
    public static function of(string $table, array $row): array
    {
        $references = [];

        foreach ($row as $column => $value) {
            $target = RowTransformer::referenceTarget($table, (string) $column);

            if ($target !== null && self::isId($value)) {
                $references[] = ['column' => (string) $column, 'table' => $target, 'id' => (int) $value];
            }
        }

        foreach (RowTransformer::POLYMORPHIC_PAIRS[$table] ?? [] as $pair) {
            $type = $row[$pair['type']] ?? null;
            $id = $row[$pair['id']] ?? null;
            $target = is_string($type) ? RowTransformer::morphTable($type) : null;

            if ($target !== null && self::isId($id)) {
                $references[] = ['column' => $pair['id'], 'table' => $target, 'id' => (int) $id];
            }
        }

        foreach (RowTransformer::JSON_ID_PATHS[$table] ?? [] as $spec) {
            if (! RowTransformer::jsonSpecApplies($spec, $row) || ! isset($row[$spec['column']])) {
                continue;
            }

            foreach (JsonIdPaths::ids($row[$spec['column']], $spec['path']) as $id) {
                $references[] = ['column' => $spec['column'], 'table' => $spec['table'], 'id' => $id];
            }
        }

        return $references;
    }

    private static function isId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }
}
