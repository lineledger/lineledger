<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id', 'attachable_type', 'attachable_id',
    'disk', 'path', 'original_filename', 'description', 'mime_type', 'size_bytes',
    'uploaded_by_id',
])]
class Attachment extends Model
{
    use BelongsToCompany;

    /**
     * MIME types that are safe to render inline in the browser (a new tab)
     * rather than forcing a download. Deliberately excludes SVG/HTML, which
     * could execute script in the app origin.
     */
    public const INLINE_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /**
     * Whether this file should open inline in a new tab when clicked.
     */
    public function isInlineViewable(): bool
    {
        return in_array((string) $this->mime_type, self::INLINE_MIME_TYPES, true);
    }

    /**
     * Whether this is a raster image, safe to render with an <img> tag. Unlike a
     * framed preview, an image is not blocked by the app's X-Frame-Options: DENY.
     */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/')
            && in_array((string) $this->mime_type, self::INLINE_MIME_TYPES, true);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    public function iconName(): string
    {
        return match (true) {
            str_starts_with((string) $this->mime_type, 'image/') => 'photo',
            $this->mime_type === 'application/pdf' => 'document-text',
            default => 'document',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
