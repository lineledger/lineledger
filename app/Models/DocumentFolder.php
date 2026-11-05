<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Services\Access\PermissionResolver;
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
     * A member whose roles open every folder (the Owner, Full Access) always
     * passes. Anyone else must be able to open Documents and either be the
     * creator or be on the explicit viewer allow-list.
     */
    public function isVisibleTo(Membership $membership): bool
    {
        $permissions = app(PermissionResolver::class)->forMembership($membership);

        if ($permissions->canView(Activity::DocumentsAllFolders)) {
            return true;
        }

        if (! $permissions->canView(Activity::Documents)) {
            return false;
        }

        if ($this->created_by_member_id !== null && $this->created_by_member_id === $membership->id) {
            return true;
        }

        return in_array($membership->id, $this->viewer_member_ids ?? [], true);
    }

    /**
     * Whether the given membership may rename, move, share or delete this folder.
     * Limited to members whose roles manage every folder (the Owner, Full
     * Access) and the folder's creator.
     */
    public function isManageableBy(Membership $membership): bool
    {
        if (app(PermissionResolver::class)->forMembership($membership)->allows(Activity::DocumentsAllFolders, PermissionAction::Modify)) {
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
