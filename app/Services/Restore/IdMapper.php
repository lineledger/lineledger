<?php

namespace App\Services\Restore;

/**
 * In-memory `[table => [oldId => newId]]` container threaded through the
 * restore loop.
 *
 * Phase 2's row-transformer rewrites parent foreign keys (e.g.
 * `invoice_lines.invoice_id`) by looking up the freshly-inserted parent id
 * in this map. One instance per restore run.
 *
 * Plain PHP — no DB, no Eloquent. Intentionally tiny so the orchestrator
 * test surface stays small.
 */
final class IdMapper
{
    /**
     * @var array<string, array<int, int>>
     */
    private array $map = [];

    /**
     * Record that row `$oldId` from `$table` was inserted with primary key
     * `$newId` on the target instance.
     */
    public function set(string $table, int $oldId, int $newId): void
    {
        $this->map[$table][$oldId] = $newId;
    }

    /**
     * Translate `$oldId` for `$table` to the new local id, or `null` if no
     * mapping has been recorded.
     */
    public function get(string $table, int $oldId): ?int
    {
        return $this->map[$table][$oldId] ?? null;
    }

    /**
     * Return `true` when an `$oldId => newId` mapping exists for `$table`.
     */
    public function has(string $table, int $oldId): bool
    {
        return isset($this->map[$table][$oldId]);
    }

    /**
     * Return the `[oldId => newId]` sub-array for `$table`, or an empty
     * array when the table has not been populated.
     *
     * @return array<int, int>
     */
    public function table(string $table): array
    {
        return $this->map[$table] ?? [];
    }

    /**
     * Return the list of table names that currently have at least one
     * recorded id mapping.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->map);
    }
}
