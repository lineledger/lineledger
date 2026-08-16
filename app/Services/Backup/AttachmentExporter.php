<?php

namespace App\Services\Backup;

use App\Models\Attachment;
use App\Models\Company;
use App\Support\Storage\StorageDisks;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies every blob referenced by `attachments` (and `companies.logo_path`)
 * into the backup working directory's `files/` subfolder.
 *
 * Returns a mapping from the source row's id → relative `files/...` path so
 * the orchestrator can rewrite the `attachments.jsonl` (and `companies.jsonl`)
 * rows to point at the in-bundle paths rather than the live storage path,
 * which becomes meaningless on a restore.
 */
final class AttachmentExporter
{
    /**
     * Walk every attachment for the company, copy each blob into the bundle,
     * and return an integrity index plus path-rewrite map.
     *
     * @return array{
     *     attachments: array<int, string>,
     *     files_count: int,
     *     bytes: int,
     *     missing: list<int>
     * }
     */
    public function exportAttachments(int $companyId, string $workDir): array
    {
        $rewrites = [];
        $missing = [];
        $filesCount = 0;
        $totalBytes = 0;

        Attachment::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->chunkById(500, function ($chunk) use (
                $workDir,
                &$rewrites,
                &$missing,
                &$filesCount,
                &$totalBytes,
            ): void {
                foreach ($chunk as $attachment) {
                    /** @var Attachment $attachment */
                    $disk = Storage::disk($attachment->disk ?: 'local');

                    if (! $disk->exists($attachment->path)) {
                        $missing[] = (int) $attachment->id;
                        Log::warning('Backup: attachment blob missing on source disk', [
                            'attachment_id' => $attachment->id,
                            'company_id' => $attachment->company_id,
                            'disk' => $attachment->disk,
                            'path' => $attachment->path,
                        ]);

                        continue;
                    }

                    $relative = $this->targetRelativePath($attachment);
                    $absolute = $workDir.'/'.$relative;
                    $this->ensureDirectory(dirname($absolute));

                    $stream = $disk->readStream($attachment->path);

                    if ($stream === false || $stream === null) {
                        // Fallback to a buffered read if the disk doesn't support streaming.
                        $contents = $disk->get($attachment->path);

                        if ($contents === null) {
                            $missing[] = (int) $attachment->id;

                            continue;
                        }

                        if (file_put_contents($absolute, $contents) === false) {
                            throw new RuntimeException("Failed to write backup blob to {$absolute}");
                        }
                    } else {
                        $sink = fopen($absolute, 'wb');

                        if ($sink === false) {
                            fclose($stream);
                            throw new RuntimeException("Failed to open backup blob for write at {$absolute}");
                        }

                        try {
                            stream_copy_to_stream($stream, $sink);
                        } finally {
                            fclose($sink);
                            fclose($stream);
                        }
                    }

                    $rewrites[(int) $attachment->id] = $relative;
                    $filesCount++;
                    $totalBytes += (int) filesize($absolute);
                }
            });

        return [
            'attachments' => $rewrites,
            'files_count' => $filesCount,
            'bytes' => $totalBytes,
            'missing' => $missing,
        ];
    }

    /**
     * Copy the company logo into the bundle (if any), returning the relative
     * `files/company-logo/...` path so the orchestrator can rewrite the
     * `companies` row's `logo_path` to point inside the bundle.
     */
    public function exportCompanyLogo(Company $company, string $workDir): ?string
    {
        return $this->exportLogoFile($company, $company->logo_path, 'files/company-logo', $workDir);
    }

    /**
     * Copy the company's document logo (printed on invoices/estimates/etc.) into
     * the bundle, returning its `files/company-document-logo/...` relative path so
     * the orchestrator can rewrite the `companies` row's `document_logo_path`.
     */
    public function exportCompanyDocumentLogo(Company $company, string $workDir): ?string
    {
        return $this->exportLogoFile($company, $company->document_logo_path, 'files/company-document-logo', $workDir);
    }

    private function exportLogoFile(Company $company, ?string $path, string $bundleDir, string $workDir): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk(StorageDisks::logos());

        if (! $disk->exists($path)) {
            Log::warning('Backup: company logo missing on source disk', [
                'company_id' => $company->id,
                'logo_path' => $path,
            ]);

            return null;
        }

        $relative = $bundleDir.'/'.$this->safeFilename(basename($path));
        $absolute = $workDir.'/'.$relative;
        $this->ensureDirectory(dirname($absolute));

        $contents = $disk->get($path);

        if ($contents === null) {
            return null;
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException("Failed to write company logo to {$absolute}");
        }

        return $relative;
    }

    /**
     * Build the in-bundle target path for an attachment, namespaced by the
     * snake-cased morph type and attachable id, prefixed with the attachment
     * id to avoid filename collisions between rows on the same parent.
     */
    private function targetRelativePath(Attachment $attachment): string
    {
        $morph = Str::snake(class_basename((string) $attachment->attachable_type));
        $morph = $morph !== '' ? $morph : 'unknown';

        $filename = $attachment->id.'_'.$this->safeFilename((string) $attachment->original_filename);

        return 'files/attachments/'.$morph.'/'.$attachment->attachable_id.'/'.$filename;
    }

    /**
     * Strip directory separators and parent-directory traversal so a hostile
     * `original_filename` cannot escape the working directory.
     */
    private function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/\.\.+/', '.', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? 'file' : $name;
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create backup directory: {$dir}");
        }
    }
}
