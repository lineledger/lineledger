<?php

namespace App\Enums;

/**
 * What a deleted transaction was when it was deleted.
 */
enum DeletedTransactionState: string
{
    /** Never posted: it never reached the ledger. */
    case Draft = 'draft';

    /** Posted: its journal entry left the ledger with it. */
    case Posted = 'posted';

    /** Voided: its entry and the void's reversing entry both left the ledger. */
    case Voided = 'voided';

    /** Deleted by an older release, which only hid it (soft delete). */
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Posted => __('Posted'),
            self::Voided => __('Voided'),
            self::Legacy => __('Draft (earlier release)'),
        };
    }
}
