<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A staged inbound document (uploaded receipt/bill or — later — an emailed
 * attachment) awaiting OCR, review and promotion into a draft bill or expense.
 *
 * @property int $id
 * @property int $company_id
 * @property InboxItemSource $source
 * @property InboxItemStatus $status
 * @property int|null $attachment_id
 * @property string|null $original_filename
 * @property string|null $mime
 * @property string|null $sender_email
 * @property array<string, mixed>|null $extracted
 * @property int|null $suggested_contact_id
 * @property string|null $suggested_document_type
 * @property string|null $promoted_document_type
 * @property int|null $promoted_document_id
 * @property string|null $ocr_error
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'company_id', 'source', 'status', 'attachment_id', 'original_filename', 'mime',
    'sender_email', 'extracted', 'suggested_contact_id', 'suggested_document_type',
    'promoted_document_type', 'promoted_document_id', 'ocr_error', 'created_by_user_id',
])]
class InboxItem extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function suggestedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'suggested_contact_id');
    }

    /**
     * The uploaded/emailed source files staged against this item. Distinct from
     * {@see attachment()}, which is the single file the OCR job read.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => InboxItemSource::class,
            'status' => InboxItemStatus::class,
            'extracted' => 'array',
            'attachment_id' => 'integer',
            'suggested_contact_id' => 'integer',
            'promoted_document_id' => 'integer',
        ];
    }
}
