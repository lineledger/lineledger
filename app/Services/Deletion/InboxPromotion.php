<?php

namespace App\Services\Deletion;

use App\Enums\InboxItemStatus;
use Illuminate\Support\Facades\DB;

/**
 * The inbox document a bill, reimbursement or expense was promoted from goes
 * back to review when that document is deleted, so it can be promoted again.
 * Its file is the inbox item's own: DeletionCleanup::removeAttachments()
 * hands it back rather than deleting it.
 */
final class InboxPromotion
{
    /**
     * @param  list<string>  $kinds  promoted_document_type values (bill, reimbursement, expense)
     */
    public static function release(array $kinds, int $documentId): void
    {
        DB::table('inbox_items')
            ->whereIn('promoted_document_type', $kinds)
            ->where('promoted_document_id', $documentId)
            ->update([
                'status' => InboxItemStatus::NeedsReview->value,
                'promoted_document_type' => null,
                'promoted_document_id' => null,
                'updated_at' => now(),
            ]);
    }
}
