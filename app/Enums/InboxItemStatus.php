<?php

namespace App\Enums;

/**
 * Lifecycle of an inbox document as it moves through OCR → review → promotion.
 * The async job advances Pending → Processing → NeedsReview (or Failed); the
 * user advances NeedsReview → Promoted or Dismissed.
 */
enum InboxItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Promoted = 'promoted';
    case Dismissed = 'dismissed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Processing => __('Reading…'),
            self::NeedsReview => __('Needs review'),
            self::Promoted => __('Promoted'),
            self::Dismissed => __('Dismissed'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * The job is still working — the UI should keep polling.
     */
    public function isProcessing(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Promoted, self::Dismissed], true);
    }
}
