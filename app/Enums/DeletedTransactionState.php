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

    /** An estimate or order, which never posts to the ledger. */
    case Unposted = 'unposted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Posted => __('Posted'),
            self::Voided => __('Voided'),
            self::Legacy => __('Draft (earlier release)'),
            self::Unposted => __('Not posted'),
        };
    }

    /**
     * Whether the transaction never reached the ledger: a draft, an estimate
     * or order, or a draft an earlier release only hid.
     */
    public function neverPosted(): bool
    {
        return in_array($this, [self::Draft, self::Unposted, self::Legacy], true);
    }
}
