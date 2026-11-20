<?php

namespace App\Services\Restore;

use App\Services\Restore\Exceptions\BundleValidationException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Unpacks a backup bundle onto local disk, safely. Shared by the importer and
 * the backup verifier so both read a bundle the same way.
 */
final class BundleExtractor
{
    /**
     * Extract `$zipAbsolute` into `$extractedDir` and return the directory that
     * holds `manifest.json`. Throws on an unreadable archive or an unsafe entry.
     */
    public function extract(string $zipAbsolute, string $extractedDir): string
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($zipAbsolute);

        if ($openResult !== true) {
            throw new RuntimeException(
                "Unable to open backup ZIP at {$zipAbsolute} (ZipArchive error code: {$openResult})."
            );
        }

        try {
            $this->assertSafeZipEntries($zip);

            if (! $zip->extractTo($extractedDir)) {
                throw new RuntimeException("Failed to extract backup ZIP to {$extractedDir}.");
            }
        } finally {
            $zip->close();
        }

        return $this->contentRoot($extractedDir);
    }

    /**
     * Recursively delete a directory. Best-effort: warnings are suppressed
     * because the caller has already done the meaningful work and a stat error
     * must not mask a real exception.
     */
    public function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Reject a hostile archive *before* extraction. An entry with an absolute
     * path, a `..` traversal segment, or a drive/UNC prefix could otherwise make
     * `ZipArchive::extractTo()` write outside the extraction directory (a "zip
     * slip"). Modern libzip guards this internally, but the bundle is fully
     * attacker-controlled (any authenticated user can upload one), so we make
     * the boundary explicit and version-independent.
     */
    private function assertSafeZipEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);

            if ($normalized === ''
                || str_starts_with($normalized, '/')
                || preg_match('#^[a-zA-Z]:#', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)
            ) {
                throw new BundleValidationException(
                    "Refusing to extract unsafe archive entry: {$name}"
                );
            }
        }
    }

    /**
     * Return the directory that actually holds `manifest.json`. Handles a bundle
     * that was extracted then re-compressed by macOS Finder, which wraps every
     * entry in a single folder alongside a `__MACOSX/` sidecar. Falls back to the
     * extraction root when there is no single wrapping folder, leaving the
     * downstream manifest checks to report the problem.
     */
    private function contentRoot(string $extractedDir): string
    {
        if (is_file($extractedDir.'/manifest.json')) {
            return $extractedDir;
        }

        foreach (glob($extractedDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === '__MACOSX') {
                continue;
            }

            if (is_file($dir.'/manifest.json')) {
                return $dir;
            }
        }

        return $extractedDir;
    }
}
