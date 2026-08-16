<?php

namespace App\Actions\Documents;

use App\Models\DocumentFolder;
use App\Models\Membership;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a folder and everything beneath it: child folders are removed
 * recursively, and every contained file is purged from storage via
 * {@see AttachmentService::remove()} (blob + row). Limited to Owner/Admin and
 * the folder's creator.
 */
final class DeleteDocumentFolder
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function handle(DocumentFolder $folder, Membership $actor): void
    {
        if (! $folder->isManageableBy($actor)) {
            abort(403);
        }

        DB::transaction(function () use ($folder): void {
            $this->deleteRecursively($folder);
        });
    }

    private function deleteRecursively(DocumentFolder $folder): void
    {
        foreach ($folder->children()->get() as $child) {
            $this->deleteRecursively($child);
        }

        foreach ($folder->attachments()->get() as $attachment) {
            $this->attachments->remove($attachment, $folder);
        }

        $folder->delete();
    }
}
