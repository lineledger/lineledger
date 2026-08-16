<?php

namespace App\Enums;

enum EstimateStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Converted = 'converted';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether an estimate in this status may be converted into an invoice.
     * QuickBooks allows converting an un-accepted quote, so both Pending and
     * Accepted qualify; terminal/derived states do not.
     */
    public function canConvert(): bool
    {
        return in_array($this, [self::Pending, self::Accepted], true);
    }

    /**
     * Whether an estimate in this status may still be edited. Locked only once
     * it has produced an invoice.
     */
    public function isEditable(): bool
    {
        return $this !== self::Converted;
    }

    /**
     * Whether this status should be re-derived against expires_on at read time.
     * Only a Pending quote expires; an Accepted one stays accepted past its date.
     */
    public function isExpirable(): bool
    {
        return $this === self::Pending;
    }
}
