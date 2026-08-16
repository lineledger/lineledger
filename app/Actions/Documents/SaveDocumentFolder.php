<?php

namespace App\Actions\Documents;

use App\Models\DocumentFolder;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a document-repository folder (rename, re-parent, or update
 * the sharing allow-list). Mutations on an existing folder are limited to
 * Owner/Admin and the creator; the action re-checks this server-side regardless
 * of what the UI allowed.
 *
 * Expected $data shape:
 *   name:               string
 *   parent_folder_id:   ?int                 (null = repository root)
 *   viewer_member_ids:  ?array<int>          (company_members.id granted view access)
 */
final class SaveDocumentFolder
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DocumentFolder $folder, Membership $actor): DocumentFolder
    {
        return DB::transaction(function () use ($data, $folder, $actor): DocumentFolder {
            $companyId = (int) app('current_company')->id;

            if ($folder && $folder->exists && ! $folder->isManageableBy($actor)) {
                abort(403);
            }

            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                abort(422, __('A folder name is required.'));
            }

            $parentId = $this->resolveParentId($data['parent_folder_id'] ?? $folder?->parent_folder_id, $folder);

            if ($folder && $folder->exists) {
                $folder->update([
                    'name' => $name,
                    'parent_folder_id' => $parentId,
                    'viewer_member_ids' => array_key_exists('viewer_member_ids', $data)
                        ? $this->sanitizeViewerIds($data['viewer_member_ids'], $companyId)
                        : $folder->viewer_member_ids,
                ]);

                return $folder;
            }

            return DocumentFolder::create([
                'name' => $name,
                'parent_folder_id' => $parentId,
                'viewer_member_ids' => $this->sanitizeViewerIds($data['viewer_member_ids'] ?? [], $companyId),
                'created_by_user_id' => $actor->user_id,
                'created_by_member_id' => $actor->id,
            ]);
        });
    }

    /**
     * Resolve and validate the requested parent folder, guarding against cycles
     * (a folder may not be moved into itself or one of its own descendants).
     */
    private function resolveParentId(mixed $value, ?DocumentFolder $folder): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // CompanyScope keeps the lookup tenant-bound; an out-of-tenant id resolves to null.
        $parent = DocumentFolder::find((int) $value);

        if ($parent === null) {
            return null;
        }

        if ($folder && $folder->exists && ($parent->id === $folder->id || $this->isDescendantOf($parent, $folder))) {
            abort(422, __('A folder cannot be moved into itself.'));
        }

        return $parent->id;
    }

    private function isDescendantOf(DocumentFolder $candidate, DocumentFolder $ancestor): bool
    {
        $cursor = $candidate;

        while ($cursor->parent_folder_id !== null) {
            if ($cursor->parent_folder_id === $ancestor->id) {
                return true;
            }

            $cursor = DocumentFolder::find($cursor->parent_folder_id);

            if ($cursor === null) {
                break;
            }
        }

        return false;
    }

    /**
     * Keep only ids that belong to a real member of this company.
     *
     * @return array<int, int>|null
     */
    private function sanitizeViewerIds(mixed $value, int $companyId): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter($value, fn ($v) => is_numeric($v)),
        )));

        if ($ids === []) {
            return null;
        }

        $valid = Membership::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $valid === [] ? null : array_values($valid);
    }
}
