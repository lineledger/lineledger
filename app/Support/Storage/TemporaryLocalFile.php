<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Runs a callback against a real filesystem path for a file that may live on
 * object storage.
 *
 * Some consumers cannot be handed a stream: `pdftotext` is a separate process
 * that takes a path argument, and the CSV/OFX parsers and the OCR/statement AI
 * clients `fopen()`/`file_get_contents()` an absolute path. On the `local` disk
 * `Storage::disk(...)->path()` answers that directly, but on S3 it throws.
 *
 * So: when the disk is local, hand back its real path and copy nothing — the
 * self-hosted path stays byte-for-byte what it was. Otherwise stream the object
 * into the system temp directory, run the callback, and delete the copy on the
 * way out (including when the callback throws).
 *
 * The callback receives the path rather than returning it, because a returned
 * path would outlive the file it names.
 */
final class TemporaryLocalFile
{
    /**
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback  Receives an absolute local path.
     * @return TReturn
     */
    public static function with(string $disk, string $path, callable $callback): mixed
    {
        $filesystem = Storage::disk($disk);

        if (StorageDisks::isLocal($disk)) {
            return $callback($filesystem->path($path));
        }

        $source = $filesystem->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException("Unable to read [{$path}] from disk [{$disk}].");
        }

        // Preserve the extension: pdftotext and several parsers sniff it.
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $temporary = tempnam(sys_get_temp_dir(), 'll-blob-');

        if ($temporary === false) {
            fclose($source);

            throw new RuntimeException('Unable to allocate a temporary file.');
        }

        if ($extension !== '') {
            $withExtension = $temporary.'.'.$extension;

            // rename() rather than a second tempnam(), so the name stays unique
            // and we never leave the extensionless stub behind.
            if (@rename($temporary, $withExtension)) {
                $temporary = $withExtension;
            }
        }

        try {
            $destination = fopen($temporary, 'wb');

            if ($destination === false) {
                throw new RuntimeException("Unable to open the temporary file [{$temporary}] for writing.");
            }

            try {
                if (stream_copy_to_stream($source, $destination) === false) {
                    throw new RuntimeException("Unable to copy [{$path}] from disk [{$disk}] to local scratch space.");
                }
            } finally {
                fclose($destination);
            }

            return $callback($temporary);
        } finally {
            fclose($source);
            self::forget($temporary);
        }
    }

    /**
     * Best-effort cleanup. A leftover temp file is a nuisance, not a failure,
     * and must never mask the callback's own exception.
     */
    private static function forget(string $path): void
    {
        try {
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            // Ignore — the OS reaps the temp directory eventually.
        }
    }
}
