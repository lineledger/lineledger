<?php

namespace App\Services\Backup\Verification;

use App\Services\Restore\BundleExtractor;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\JsonlTableReader;
use Generator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * A backup bundle unpacked to a scratch directory for reading. Call
 * {@see self::close()} when done (the destructor does too).
 */
final class BundleReader
{
    private bool $closed = false;

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function __construct(
        public readonly string $zipPath,
        public readonly string $root,
        private readonly string $extractRoot,
        private readonly array $manifest,
    ) {}

    /**
     * Unpack `$zipPath`. Throws {@see BundleValidationException} when it isn't
     * a readable bundle (not a ZIP, an unsafe entry, no valid manifest).
     */
    public static function open(string $zipPath): self
    {
        $extractor = app(BundleExtractor::class);
        $extractRoot = storage_path('app/private/backups/verify/'.Str::uuid()->toString());

        if (! @mkdir($extractRoot, 0700, true) && ! is_dir($extractRoot)) {
            throw new RuntimeException("Unable to create a working directory at {$extractRoot}.");
        }

        try {
            $root = $extractor->extract($zipPath, $extractRoot);

            $raw = is_file($root.'/manifest.json') ? file_get_contents($root.'/manifest.json') : false;

            if ($raw === false) {
                throw new BundleValidationException('The backup has no manifest.json.');
            }

            try {
                $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new BundleValidationException('The backup\'s manifest.json is not valid JSON: '.$e->getMessage());
            }

            if (! is_array($manifest)) {
                throw new BundleValidationException('The backup\'s manifest.json is not an object.');
            }
        } catch (\Throwable $e) {
            $extractor->remove($extractRoot);

            throw $e instanceof BundleValidationException
                ? $e
                : new BundleValidationException($e->getMessage(), previous: $e);
        }

        return new self($zipPath, $root, $extractRoot, $manifest);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * The manifest's per-table entries ({rows, sha256, bytes, …}).
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifestTables(): array
    {
        $tables = $this->manifest['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }

    /**
     * The bundle's referenced users (`users.json`), or an empty list.
     *
     * @return list<array{id: int, email: string, name: string}>
     */
    public function users(): array
    {
        $path = $this->root.'/users.json';
        $raw = is_file($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function tablePath(string $table): string
    {
        return $this->root.'/data/'.$table.'.jsonl';
    }

    public function hasTable(string $table): bool
    {
        return is_file($this->tablePath($table));
    }

    /**
     * Every row of `$table` as the bundle holds it, in file order. A table the
     * bundle has no file for yields nothing.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $table): Generator
    {
        if (! $this->hasTable($table)) {
            return;
        }

        yield from app(JsonlTableReader::class)->read($this->tablePath($table));
    }

    /**
     * The table files the bundle holds, by table name.
     *
     * @return list<string>
     */
    public function tableFiles(): array
    {
        return array_map(
            static fn (string $file): string => basename($file, '.jsonl'),
            glob($this->root.'/data/*.jsonl') ?: [],
        );
    }

    /**
     * Absolute path of a bundle-relative path (`files/attachments/…`), or null
     * when it would escape the bundle.
     */
    public function path(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        return $this->root.'/'.$relative;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        app(BundleExtractor::class)->remove($this->extractRoot);
    }

    public function __destruct()
    {
        $this->close();
    }
}
