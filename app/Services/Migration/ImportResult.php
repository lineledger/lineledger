<?php

namespace App\Services\Migration;

/**
 * Outcome of either a preview() or commit() run.
 *
 * In preview mode, callers should inspect $errors and $previewRows to surface
 * issues to the user before they hit "Commit". In commit mode, $createdIds
 * and $summary describe what landed.
 */
final class ImportResult
{
    /**
     * @param  list<array<string, mixed>>  $previewRows
     * @param  list<array{row:int, message:string}>  $errors
     * @param  list<int>  $createdIds
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly bool $isDryRun,
        public readonly array $previewRows,
        public readonly array $errors,
        public readonly array $createdIds = [],
        public readonly array $summary = [],
    ) {}

    public function isOk(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function rowCount(): int
    {
        return count($this->previewRows);
    }
}
