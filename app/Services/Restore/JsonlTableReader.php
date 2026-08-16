<?php

namespace App\Services\Restore;

use Generator;
use JsonException;
use RuntimeException;

/**
 * Streaming JSON-Lines reader used by the restore pipeline.
 *
 * Reads a file line-by-line with `fopen` + `fgets` so memory stays bounded
 * regardless of file size (Phase 2 must handle 100k+ row tables produced by
 * Phase 1's `JsonlTableWriter`).
 *
 * The reader is the inverse of `App\Services\Backup\JsonlTableWriter`:
 * one JSON object per line, blank lines tolerated, decoded assoc arrays
 * yielded in order.
 */
final class JsonlTableReader
{
    /**
     * Yield each non-empty line of `$path` as an associative array.
     *
     * Malformed JSON propagates as `\JsonException` so the orchestrator can
     * surface a precise error to the user.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException When the file cannot be opened.
     * @throws JsonException When a line is not valid JSON.
     */
    public function read(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open JSONL file for read: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if ($trimmed === '') {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $row = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Count the number of decodable rows in `$path` without retaining any
     * row in memory. Used by the bundle inspector to cross-check manifest
     * row counts before kicking off a restore.
     *
     * @throws RuntimeException When the file cannot be opened.
     * @throws JsonException When a line is not valid JSON.
     */
    public function count(string $path): int
    {
        $rows = 0;

        foreach ($this->read($path) as $_row) {
            $rows++;
        }

        return $rows;
    }
}
