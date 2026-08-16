<?php

namespace App\Services;

use App\Models\Attachment;
use App\Support\Storage\StorageDisks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class AttachmentService
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

    /** Per-file upload cap for attachments (10 MB), shared across the app. */
    public const MAX_KILOBYTES = 10 * 1024;

    public const CUSTOMER_MAX_KILOBYTES = 10 * 1024;

    /**
     * The document repository accepts a few extra office/text formats, but the
     * same 10 MB per-file size cap as everywhere else.
     */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx',
        'csv', 'txt', 'ppt', 'pptx', 'odt', 'ods',
    ];

    public const DOCUMENT_MAX_KILOBYTES = 10 * 1024;

    /**
     * Validation rules for a multi-file upload field. Defaults match the
     * transaction-attachment limits; the document repository passes its own
     * larger cap and wider extension list.
     *
     * @param  array<int, string>|null  $allowedExtensions
     * @return array<string, array<int, mixed>>
     */
    public static function uploadRules(string $field = 'newAttachments', ?int $maxKilobytes = null, ?array $allowedExtensions = null): array
    {
        return [
            $field.'.*' => [
                'file',
                File::types($allowedExtensions ?? self::ALLOWED_EXTENSIONS)->max($maxKilobytes ?? self::MAX_KILOBYTES),
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $uploads
     */
    public function upload(Model $attachable, array $uploads, ?int $uploaderId = null): int
    {
        $created = 0;

        DB::transaction(function () use ($attachable, $uploads, $uploaderId, &$created) {
            foreach ($uploads as $upload) {
                if (! $upload instanceof TemporaryUploadedFile) {
                    continue;
                }

                $originalName = $this->sanitizeFilename($upload->getClientOriginalName());
                $mimeType = $upload->getMimeType() ?: 'application/octet-stream';
                $size = (int) ($upload->getSize() ?: 0);
                $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->guessExtension() ?: 'bin');

                $filename = (string) Str::ulid().'.'.$extension;
                $directory = 'attachments/'.$attachable->getAttribute('company_id').'/'.$attachable->getTable().'/'.$attachable->getKey();

                // The disk is recorded per row, not assumed at read time, so a
                // deployment that switches to object storage keeps serving the
                // blobs written before the switch.
                $disk = StorageDisks::attachments();

                $path = $upload->storeAs($directory, $filename, $disk);

                // A false return means the bytes never landed. Writing the row
                // anyway would leave an attachment pointing at nothing, so fail
                // the whole transaction instead.
                if ($path === false) {
                    throw new RuntimeException("Unable to store [{$originalName}] on disk [{$disk}].");
                }

                Attachment::create([
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $originalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'uploaded_by_id' => $uploaderId,
                ]);

                $created++;
            }
        });

        return $created;
    }

    /**
     * Strip any path components and control characters from the client-supplied
     * filename before it is stored or echoed in download headers / the UI.
     */
    protected function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'file' : Str::limit($name, 255, '');
    }

    public function remove(Attachment $attachment, Model $expectedAttachable): void
    {
        $this->assertBelongsTo($attachment, $expectedAttachable);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Rename a file and/or set its description. The on-disk blob is untouched —
     * only the display name and description metadata change.
     */
    public function updateMeta(Attachment $attachment, Model $expectedAttachable, string $filename, ?string $description): void
    {
        $this->assertBelongsTo($attachment, $expectedAttachable);

        $name = $this->sanitizeFilename($filename);

        $attachment->update([
            'original_filename' => $name,
            'description' => $this->normalizeDescription($description),
        ]);
    }

    /**
     * Set just the description on a company-scoped attachment (used by the
     * cross-transaction attachment index, where there is no single attachable
     * to guard against — tenancy is already enforced by the CompanyScope).
     */
    public function setDescription(Attachment $attachment, ?string $description): void
    {
        $attachment->update(['description' => $this->normalizeDescription($description)]);
    }

    protected function normalizeDescription(?string $description): ?string
    {
        $description = $description === null ? '' : trim($description);

        return $description === '' ? null : Str::limit($description, 500, '');
    }

    protected function assertBelongsTo(Attachment $attachment, Model $expectedAttachable): void
    {
        if ($attachment->attachable_id !== $expectedAttachable->getKey() ||
            $attachment->attachable_type !== $expectedAttachable->getMorphClass()) {
            abort(404);
        }
    }
}
