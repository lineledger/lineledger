<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\CompanyRole;
use App\Enums\Section;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A folder in the company document repository. Files dropped into a folder are
 * stored as polymorphic {@see Attachment} rows (attachable = this folder), so
 * uploads, downloads, backup and restore all reuse the existing attachment
 * plumbing. Folders are private by default: a new folder is visible only to its
 * creator plus Owner/Admin until members are explicitly added to
 * {@see $viewer_member_ids}.
 */
#[Fillable([
    'company_id', 'parent_folder_id', 'name',
    'viewer_member_ids', 'created_by_user_id', 'created_by_member_id',
])]
class DocumentFolder extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_folder_id');
    }

    /**
     * @return HasMany<DocumentFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_folder_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Whether the given membership may view this folder (and download its files).
     *
     * Owner/Admin always pass. Any other member must hold the Documents section
     * and either be the creator or be on the explicit viewer allow-list.
     */
    public function isVisibleTo(Membership $membership): bool
    {
        if ($membership->role === CompanyRole::Owner || $membership->role === CompanyRole::Admin) {
            return true;
        }

        if (! $membership->canAccessSection(Section::Documents)) {
            return false;
        }

        if ($this->created_by_member_id !== null && $this->created_by_member_id === $membership->id) {
            return true;
        }

        return in_array($membership->id, $this->viewer_member_ids ?? [], true);
    }

    /**
     * Whether the given membership may rename, move, share or delete this folder.
     * Limited to Owner/Admin and the folder's creator.
     */
    public function isManageableBy(Membership $membership): bool
    {
        if ($membership->role === CompanyRole::Owner || $membership->role === CompanyRole::Admin) {
            return true;
        }

        return $this->created_by_member_id !== null && $this->created_by_member_id === $membership->id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewer_member_ids' => 'array',
            'created_by_member_id' => 'integer',
        ];
    }
}
