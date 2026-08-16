<?php

namespace App\Services\Restore;

use App\Support\Storage\StorageDisks;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Inverse of `App\Services\Backup\AttachmentExporter`.
 *
 * After the orchestrator has inserted the bundle's `attachments` rows for the
 * new company (with `path` still pointing at the bundle-relative location, e.g.
 * `files/attachments/contact/12/345_invoice.pdf`), this importer:
 *
 *  1. Streams each blob from the extracted bundle directory back onto this
 *     instance's configured attachment disk.
 *  2. Sanitizes the final on-disk path so a hostile `original_filename` from
 *     the bundle cannot escape the per-company directory.
 *  3. Updates each `attachments` row's `disk` + `path` columns to the final
 *     disk-relative location.
 *
 * Substitution rules:
 *  - The bundle's recorded `disk` is metadata about where the file used to
 *     live, not an instruction. Blobs always land on `StorageDisks::attachments()`
 *     — a `local` bundle imported onto an object-storage install must not
 *     scatter fresh files onto the local filesystem, and vice versa. Any
 *     difference is tallied so the orchestrator can surface it.
 *  - Missing source blobs are counted but never throw — the source bundle may
 *     have flagged them as known orphans (Phase 1 records the same condition).
 *
 * The company logo goes to `StorageDisks::logos()` (see `Company::logoUrl`) and
 * is round-tripped the same way via a dedicated pass.
 */
final class AttachmentImporter
{
    private const LOGO_DIRECTORY = 'company-logos';

    /**
     * Walk the new company's freshly-inserted `attachments` rows, copy each
     * referenced blob from the extracted bundle onto the target disk, and
     * update the row to point at the final disk-relative location.
     *
     * @return array{
     *     copied: int,
     *     missing: int,
     *     bytes: int,
     *     substituted_disk: int,
     *     errors: array<int, string>
     * }
     */
    public function importAttachments(int $newCompanyId, string $extractedDir, IdMapper $idMapper): array
    {
        $copied = 0;
        $missing = 0;
        $bytes = 0;
        $substitutedDisk = 0;
        $errors = [];

        $rows = DB::table('attachments')
            ->where('company_id', $newCompanyId)
            ->get(['id', 'disk', 'path', 'original_filename']);

        foreach ($rows as $row) {
            $sourceAbsolute = $this->confinedSourcePath($extractedDir, (string) $row->path);

            if ($sourceAbsolute === null || ! is_file($sourceAbsolute)) {
                $missing++;
                Log::warning('Restore: attachment blob missing from bundle or path rejected', [
                    'company_id' => $newCompanyId,
                    'attachment_id' => $row->id,
                    'bundle_path' => $row->path,
                ]);

                continue;
            }

            $requestedDisk = (string) ($row->disk ?: StorageDisks::attachments());
            $targetDisk = $this->resolveTargetDisk($requestedDisk, $substituted);

            if ($substituted) {
                $substitutedDisk++;
            }

            $safeFilename = $this->safeFilename((string) $row->original_filename);
            $targetPath = sprintf(
                'attachments/%d/%d_%s',
                $newCompanyId,
                (int) $row->id,
                $safeFilename,
            );

            try {
                $this->copyToDisk($sourceAbsolute, $targetDisk, $targetPath);
            } catch (Throwable $e) {
                $errors[] = sprintf(
                    'attachment %d: %s',
                    $row->id,
                    $e->getMessage(),
                );
                Log::warning('Restore: failed to copy attachment blob to target disk', [
                    'company_id' => $newCompanyId,
                    'attachment_id' => $row->id,
                    'target_disk' => $targetDisk,
                    'target_path' => $targetPath,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            DB::table('attachments')
                ->where('id', $row->id)
                ->update([
                    'disk' => $targetDisk,
                    'path' => $targetPath,
                ]);

            $copied++;
            $bytes += (int) filesize($sourceAbsolute);
        }

        unset($idMapper); // Reserved for future per-attachment lookups; intentionally not used today.

        return [
            'copied' => $copied,
            'missing' => $missing,
            'bytes' => $bytes,
            'substituted_disk' => $substitutedDisk,
            'errors' => $errors,
        ];
    }

    /**
     * Copy the company logo (if any) from `files/company-logo/` in the
     * extracted bundle onto the `public` disk and return the new
     * `logo_path` so callers can surface it. Also updates the row.
     */
    public function importCompanyLogo(int $newCompanyId, string $extractedDir): ?string
    {
        return $this->importLogoFile($newCompanyId, $extractedDir, 'files/company-logo', 'logo_path');
    }

    /**
     * Copy the company's document logo (if any) from `files/company-document-logo/`
     * onto the `public` disk and update `document_logo_path` on the new company.
     */
    public function importCompanyDocumentLogo(int $newCompanyId, string $extractedDir): ?string
    {
        return $this->importLogoFile($newCompanyId, $extractedDir, 'files/company-document-logo', 'document_logo_path');
    }

    private function importLogoFile(int $newCompanyId, string $extractedDir, string $bundleDir, string $column): ?string
    {
        $logoDir = rtrim($extractedDir, '/').'/'.$bundleDir;

        if (! is_dir($logoDir)) {
            return null;
        }

        // Skip macOS AppleDouble (`._logo.png`) / .DS_Store siblings so a
        // re-zipped bundle doesn't restore the resource-fork stub as the logo.
        $candidates = array_values(array_filter(
            (array) glob($logoDir.'/*'),
            static fn (string $candidate): bool => is_file($candidate) && ! BundleLayout::isCruft($candidate),
        ));

        if ($candidates === []) {
            return null;
        }

        $sourceAbsolute = $candidates[0];
        $safeFilename = $this->safeFilename(basename($sourceAbsolute));
        $targetPath = self::LOGO_DIRECTORY.'/'.$safeFilename;

        $this->copyToDisk($sourceAbsolute, StorageDisks::logos(), $targetPath);

        DB::table('companies')
            ->where('id', $newCompanyId)
            ->update([$column => $targetPath]);

        return $targetPath;
    }

    /**
     * Resolve which disk a copy should land on.
     *
     * Restored blobs always go to whatever disk this instance is configured to
     * write attachments to, regardless of where they lived on the instance that
     * produced the bundle — importing a `local` bundle onto an object-storage
     * install must not scatter fresh files back onto the local filesystem. The
     * substitution is flagged so the orchestrator can report it.
     */
    private function resolveTargetDisk(string $requestedDisk, ?bool &$substituted): string
    {
        $targetDisk = StorageDisks::attachments();
        $substituted = $requestedDisk !== $targetDisk;

        return $targetDisk;
    }

    /**
     * Stream the source file onto the target disk at the given relative
     * path, preferring `writeStream` for memory-safety and falling back to
     * a buffered `put` when the disk driver doesn't expose streaming.
     */
    private function copyToDisk(string $sourceAbsolute, string $targetDisk, string $targetPath): void
    {
        $disk = Storage::disk($targetDisk);

        $stream = @fopen($sourceAbsolute, 'rb');

        if ($stream === false) {
            throw new FileNotFoundException("Unable to open source blob: {$sourceAbsolute}");
        }

        try {
            $written = $disk->writeStream($targetPath, $stream);

            if ($written === false) {
                // Some adapters don't return a bool — but if they explicitly
                // return false we treat it as a failure and try the buffered
                // fallback before giving up.
                $contents = file_get_contents($sourceAbsolute);

                if ($contents === false || ! $disk->put($targetPath, $contents)) {
                    throw new \RuntimeException("Failed to write blob to disk [{$targetDisk}] at {$targetPath}");
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Resolve a bundle-relative attachment `path` to an absolute filesystem
     * path *confined to `$extractedDir`*, or null if it cannot be trusted.
     *
     * `attachments.path` is taken verbatim from the uploaded bundle
     * (RowTransformer preserves it), so a hostile row like `../../../.env`
     * would otherwise escape the extracted bundle and let the restorer read
     * arbitrary server files (APP_KEY, DB creds, other tenants' blobs) into a
     * company store it can then download. We reject anything that isn't a plain
     * relative path under the exporter's `files/` root, and `realpath()`-confirm
     * the resolved target still lives inside the bundle (also defeating symlinks)
     * before the caller ever opens it. Returns null for rejected or non-existent
     * paths, which the caller counts as a missing blob (non-fatal, same as an
     * orphaned attachment).
     */
    private function confinedSourcePath(string $extractedDir, string $bundlePath): ?string
    {
        $bundlePath = str_replace('\\', '/', trim($bundlePath));

        // Empty, absolute, or Windows drive/UNC forms are never valid bundle paths.
        if ($bundlePath === ''
            || str_starts_with($bundlePath, '/')
            || preg_match('#^[a-zA-Z]:#', $bundlePath) === 1
        ) {
            return null;
        }

        // The exporter only ever writes blobs under the bundle `files/` root.
        if (! str_starts_with($bundlePath, 'files/')) {
            return null;
        }

        // Reject any `..` traversal segment before it can escape the bundle.
        if (in_array('..', explode('/', $bundlePath), true)) {
            return null;
        }

        // realpath() collapses `.`/`..`/symlinks and returns false for a path
        // that doesn't exist — so a valid result both exists and is canonical.
        $realBase = realpath($extractedDir);
        $realCandidate = realpath(rtrim($extractedDir, '/').'/'.$bundlePath);

        if ($realBase === false || $realCandidate === false) {
            return null;
        }

        if ($realCandidate !== $realBase && ! str_starts_with($realCandidate, $realBase.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realCandidate;
    }

    /**
     * Strip directory separators, traversal sequences, and trim to a sane
     * length so a hostile `original_filename` cannot land outside of the
     * per-company directory.
     */
    private function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/\.\.+/', '.', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            $name = 'file';
        }

        if (mb_strlen($name) > 200) {
            $name = mb_substr($name, 0, 200);
        }

        return $name;
    }
}
